<?php
declare(strict_types=1);
namespace Furoeducare\Modules\Settings;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthInput;
use Furoeducare\Modules\Auth\AuthMailer;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Auth\Role;

final class SettingsController
{
    private const ROOT = '/settings/account';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
    public function __construct(private readonly View $view, private readonly AuthSession $session,
        private readonly AuthAccess $access, private readonly RateLimiter $limits, private readonly AuthMailer $mailer,
        private readonly AccountSettings $settings, private readonly AppearancePreferences $appearance) {}

    public function account(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) return $this->redirect('/login');
        if ($request->method !== 'POST') return $this->page($viewer);
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) return $this->page($viewer, ['_form' => 'The form expired. Reopen settings and try again.'], status: 403);
        $action = $request->form('action');
        $fields = ['password' => ['current_password', 'password', 'password_confirmation'], 'email' => ['current_password', 'new_email'],
            'cancel_email' => ['current_password'], 'phone' => ['current_password', 'profile_role', 'phone'],
            'admin_profile' => ['current_password', 'display_name'], 'appearance' => ['appearance', 'return_to'],
            'deactivate' => ['current_password', 'deactivation_reason', 'confirmed'], 'cancel_deactivation' => ['current_password']];
        if (!isset($fields[$action]) || array_diff($request->formKeys(), [...($fields[$action] ?? []), '_csrf', 'action']) !== []) {
            return $this->page($viewer, ['_form' => 'Choose an available settings action.'], status: 422);
        }
        $values = ['_action' => $action];
        foreach (['new_email', 'phone', 'profile_role', 'display_name', 'deactivation_reason'] as $field) {
            if (in_array($field, $fields[$action], true)) $values[$field] = $request->form($field);
        }
        try {
            if ($action === 'appearance') {
                $this->appearance->save($viewer['id'], $request->form('appearance'), (int) $this->session->account()['version']);
                return $this->saved('Appearance saved to your account.', SettingsInput::returnPath($request->form('return_to')));
            }
            $this->rateLimit($request, $viewer['id'], $action);
            $version = (int) $this->session->account()['version'];
            $current = $request->form('current_password');
            switch ($action) {
                case 'password':
                    $version = $this->settings->changePassword($viewer['id'], $version, $current, $request->form('password'), $request->form('password_confirmation'));
                    $this->renew($viewer, $version);
                    return $this->saved('Password changed. Other sessions and outstanding security links have ended.');
                case 'email':
                    if (!$this->mailer->ready()) throw new DomainException('Account email is temporarily unavailable. Your current address has not changed.', 503);
                    $this->settings->requestEmail($viewer['id'], $version, $current, $request->form('new_email'));
                    return $this->saved('Verification queued for the proposed address. Your current email remains your sign-in address until confirmation.');
                case 'cancel_email': $this->settings->cancelEmail($viewer['id'], $version, $current); return $this->saved('Pending email changes cancelled.');
                case 'phone':
                    $role = Role::tryFrom($request->form('profile_role'));
                    if ($role === null) throw new SettingsValidation(['phone' => 'Choose one of your contact profiles.']);
                    $this->settings->updatePhone($viewer['id'], $version, $current, $role, $request->form('phone'));
                    return $this->saved('Phone updated. Changes to reviewed contact details may require renewed review.');
                case 'admin_profile': $this->settings->updateStaffName($viewer['id'], $version, $current, $request->form('display_name')); return $this->saved('Staff profile saved.');
                case 'deactivate': $this->settings->requestDeactivation($viewer['id'], $version, $current, $request->form('deactivation_reason'), $request->form('confirmed') === '1'); return $this->saved('Deactivation request submitted to management for review.');
                case 'cancel_deactivation': $this->settings->cancelDeactivation($viewer['id'], $version, $current); return $this->saved('Deactivation request cancelled.');
            }
        } catch (SettingsValidation $error) { return $this->page($viewer, $error->errors, $values, 422); }
        catch (DomainException $error) { return $this->page($viewer, ['_form' => $error->getMessage()], $values, $error->getCode() ?: 403); }
        throw new \LogicException('Unreachable settings action.');
    }

    public function emailChange(Request $request): Response
    {
        $token = $request->method === 'POST' ? $request->form('token') : $request->query('token');
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            if (AuthInput::validToken($token)) $this->session->rememberEmailChange($token);
            return $this->redirect('/login');
        }
        $version = (int) $this->session->account()['version'];
        $errors = [];
        $status = 200;
        try {
            if ($request->method === 'POST') {
                if (!$this->session->acceptsCsrf($request->form('_csrf'))) throw new DomainException('The form expired. Reopen the email link and try again.', 403);
                if (array_diff($request->formKeys(), ['token', '_csrf', 'current_password']) !== []) throw new DomainException('Invalid email confirmation fields.', 422);
                $this->rateLimit($request, $viewer['id'], 'confirm_email');
                $version = $this->settings->confirmEmail($viewer['id'], $version, $request->form('current_password'), $token);
                $this->renew($viewer, $version);
                return $this->saved('Your new email is verified and is now your sign-in address. Other sessions have ended.');
            }
        } catch (SettingsValidation $error) { $errors = $error->errors; $status = 422; }
        catch (DomainException $error) { $errors = ['_form' => $error->getMessage()]; $status = $error->getCode() ?: 403; }
        $challenge = $this->settings->emailChallenge($viewer['id'], $version, $token);
        if ($challenge === null && $errors === []) { $errors = ['_form' => 'This link is unavailable, expired, or belongs to another account.']; $status = 410; }
        return Response::html($this->view->render(__DIR__ . '/Views/email-change.php', 'Verify new email', [
            'signedIn' => true, 'csrf' => $this->session->csrf(), 'errors' => $errors,
            'challenge' => $challenge, 'token' => $challenge !== null ? $token : '',
        ]), $status, self::HEADERS);
    }

    private function page(array $viewer, array $errors = [], array $values = [], int $status = 200): Response
    {
        $data = $this->settings->read($viewer['id']);
        $values += ['display_name' => $data['staffDisplayName']];
        return Response::html($this->view->render(__DIR__ . '/Views/account.php', 'Account settings', [
            'signedIn' => true, 'csrf' => $this->session->csrf(), 'viewer' => $viewer, 'notice' => $this->session->takeNotice(),
            'errors' => $errors, 'values' => $values, 'appearance' => $this->appearance->get($viewer['id']),
        ] + $data), $status, self::HEADERS);
    }

    private function rateLimit(Request $request, int $id, string $action): void
    {
        if (!$this->limits->allow('settings-account', (string) $id, 30, 900)
            || !$this->limits->allow('settings-network', RateLimiter::network($request->remoteAddress), 100, 900)
            || !$this->limits->allow('settings-action-' . $action, (string) $id, $action === 'email' ? 6 : 10, 900)) {
            throw new DomainException('Too many sensitive settings attempts. Try again in 15 minutes.', 429);
        }
    }

    private function renew(array $viewer, int $version): void
    {
        $context = Role::tryFrom($this->session->dashboardContext() ?? '');
        $this->session->authenticate($viewer['id'], $version);
        if ($context !== null && in_array($context, $viewer['roles'], true)) $this->session->dashboardContext($context);
    }
    private function saved(string $notice, string $path = self::ROOT): Response { $this->session->notice($notice); return $this->redirect($path); }
    private function redirect(string $path): Response { return new Response('', 303, ['Location' => $path] + self::HEADERS); }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

use Closure;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;

abstract class ProfileRegistration
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly AuthService $accounts,
        private readonly AuthMailer $mailer,
        private readonly RateLimiter $limits,
        private readonly Role $role,
        private readonly Closure $validate,
        private readonly Closure $createProfile,
        private readonly string $template,
        private readonly string $registrationTitle,
        private readonly string $verificationTitle,
        private readonly string $verifiedNotice,
    ) {
    }

    public function register(Request $request): Response
    {
        if (($viewer = $this->access->viewer()) !== null) {
            return $this->redirect($viewer['roles'][0]->dashboard());
        }
        if ($request->method !== 'POST') {
            return $this->form();
        }
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->form(message: 'The form expired. Reopen it and try again.', status: 403);
        }

        [$values, $errors] = ($this->validate)($request);
        if ($errors !== []) {
            return $this->form(values: $values, errors: $errors, status: 422);
        }

        $email = $values['email'];
        if (!$this->limits->allow('register-network', RateLimiter::network($request->remoteAddress), 12, 900)
            || !$this->limits->allow('register-email', $email, 4, 900)) {
            return $this->form(values: $values, message: 'Too many attempts. Wait before trying again.', status: 429);
        }
        if (!$this->mailer->ready()) {
            return $this->form(values: $values, message: 'Registration email is temporarily unavailable.', status: 503);
        }

        $hash = password_hash($request->form('password'), PASSWORD_DEFAULT);
        $started = hrtime(true);
        $token = $this->accounts->register($email) ?? $this->accounts->requestLink(EmailToken::Verification, $email);
        if ($token !== null) {
            $this->session->rememberRegistration($this->role, $token, [
                'values' => $values, 'password_hash' => $hash,
                'terms_version' => LegalNotice::TERMS, 'privacy_version' => LegalNotice::PRIVACY,
            ]);
            $this->mailer->deliver(EmailToken::Verification, $email, $token, flow: $this->role,
                teacherRequestReference: $this->session->teacherRequestReference());
        }
        $remaining = 300_000 - (int) ((hrtime(true) - $started) / 1000);
        if ($remaining > 0) {
            usleep($remaining);
        }
        $this->session->notice('If this address can be registered, check your inbox for a verification link.');
        return $this->redirect('/login');
    }

    public function verify(Request $request): Response
    {
        if (($viewer = $this->access->viewer()) !== null) {
            return $this->redirect($viewer['roles'][0]->dashboard());
        }
        $submit = $request->method === 'POST';
        $reference = AuthInput::teacherRequestReference($submit ? $request->form('ref') : $request->query('ref'));
        if ($submit && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->form(mode: 'verify', message: 'The form expired. Reopen the verification link.', status: 403, reference: $reference);
        }
        $token = $submit ? $request->form('token') : $request->query('token');
        if (!AuthInput::validToken($token) || (!$submit && !$this->accounts->canConfirmProfileEmail($token))) {
            return $this->invalidLink($reference);
        }
        if ($submit && (!$this->limits->allow($this->role->value . '-verify-network', RateLimiter::network($request->remoteAddress), 30, 900)
            || !$this->limits->allow($this->role->value . '-verify-token', $token, 8, 900))) {
            return $this->form(mode: 'verify', message: 'Too many verification attempts. Wait before trying again.', status: 429, reference: $reference);
        }
        $draft = $this->session->pendingRegistration($this->role, $token);
        if ($draft !== null && ($draft['terms_version'] !== LegalNotice::TERMS || $draft['privacy_version'] !== LegalNotice::PRIVACY)) {
            $draft = null;
        }
        if (!$submit) {
            return $this->form(mode: 'verify', values: $draft['values'] ?? [], token: $token, confirm: $draft !== null, reference: $reference);
        }

        if ($draft !== null && $request->form('confirm_registration') === '1') {
            $values = $draft['values'];
            $hash = static fn (): string => $draft['password_hash'];
        } else {
            [$values, $errors] = ($this->validate)($request);
            if ($errors !== []) {
                return $this->form(mode: 'verify', values: $values, errors: $errors, token: $token, status: 422, reference: $reference);
            }
            $password = $request->form('password');
            $hash = static fn (): string => password_hash($password, PASSWORD_DEFAULT);
        }

        try {
            $account = $this->accounts->confirmProfileEmail($this->role, $token, $values['email'], $hash,
                fn (int $id) => ($this->createProfile)($id, $values));
        } catch (RegistrationConflict $error) {
            return $this->form(mode: 'verify', values: $values, token: $token,
                message: $error->getMessage(), status: 409, reference: $reference);
        }
        if ($account === null) {
            return $this->invalidLink($reference);
        }

        $destination = $this->session->teacherRequestPath($reference) ?? $this->role->dashboard();
        $this->session->authenticate($account['id'], $account['version']);
        $this->session->notice($this->verifiedNotice);
        return $this->redirect($destination);
    }

    private function invalidLink(?string $reference): Response
    {
        return $this->form(mode: 'verify', message: 'This verification link is invalid or expired. Request a new link.', status: 422, reference: $reference);
    }

    private function form(
        string $mode = 'register', array $values = [], array $errors = [], string $token = '',
        bool $confirm = false, string $message = '', int $status = 200, ?string $reference = null,
    ): Response {
        $title = $mode === 'register' ? $this->registrationTitle : $this->verificationTitle;
        $data = compact('mode', 'values', 'errors', 'token', 'confirm', 'message', 'reference') + ['csrf' => $this->session->csrf()];
        return Response::html($this->view->render($this->template, $title, $data),
            $status, self::HEADERS + ($status === 429 ? ['Retry-After' => '900'] : []));
    }

    private function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

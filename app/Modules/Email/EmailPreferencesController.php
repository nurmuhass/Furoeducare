<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use InvalidArgumentException;
use RuntimeException;

final class EmailPreferencesController
{
    public const ROOT = '/settings/notifications';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly EmailPreferences $preferences,
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return new Response('', 303, ['Location' => '/login'] + self::HEADERS);
        }
        if (!in_array($request->method, ['GET', 'POST'], true)) {
            return $this->problem('Method unavailable', 'Use the notification settings form to update your preferences.', 405);
        }
        if ($request->method === 'POST') {
            if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
                return $this->problem('Form expired', 'Reopen notification settings and try again.', 403);
            }
            // The owner always comes from the current verified session, never submitted data.
            foreach (['id', 'user', 'user_id', 'owner', 'owner_id', 'account_id'] as $field) {
                if ($request->form($field) !== '' || $request->query($field) !== '' || $request->formArray($field) !== []) {
                    return $this->problem('Invalid preferences', 'Notification preferences can only be changed for your own account.', 422);
                }
            }
            $enabled = $request->formArray('enabled');
            if ($request->form('enabled') !== '') {
                return $this->page($viewer['id'], 'Choose valid notification categories.', 422);
            }
            try {
                $this->preferences->save($viewer['id'], $enabled);
            } catch (InvalidArgumentException) {
                return $this->page($viewer['id'], 'Choose valid notification categories.', 422);
            } catch (RuntimeException $error) {
                // Database errors remain operational failures; only the explicit account guard is a denial.
                if ($error::class !== RuntimeException::class) {
                    throw $error;
                }
                return $this->problem('Access denied', 'This account cannot change notification preferences.', 403);
            }
            $this->session->notice('Your email notification preferences have been saved.');
            return new Response('', 303, ['Location' => self::ROOT] + self::HEADERS);
        }
        return $this->page($viewer['id']);
    }

    private function page(int $user, string $error = '', int $status = 200): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/preferences.php', 'Email notification settings', [
            'signedIn' => true, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
            'path' => self::ROOT, 'categories' => EmailPreferences::CATEGORIES,
            'enabled' => $this->preferences->forUser($user), 'error' => $error,
        ]), $status, self::HEADERS);
    }

    private function problem(string $title, string $message, int $status): Response
    {
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
            'message' => $message, 'signedIn' => true, 'csrf' => $this->session->csrf(),
        ]), $status, self::HEADERS);
    }
}

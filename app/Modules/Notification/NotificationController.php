<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Notification;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;

final class NotificationController
{
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly NotificationInbox $inbox,
        private readonly NotificationPreferences $settings,
    ) {
    }

    public function feed(Request $request): Response
    {
        return $this->authenticated(function (array $viewer) use ($request): Response {
            $before = $request->query('before') === '' ? PHP_INT_MAX : self::id($request->query('before'));
            $category = $request->query('category');
            if ($category !== '' && !isset(NotificationPreferences::CATEGORIES[$category])) {
                throw new DomainException('Choose an available notification category.', 422);
            }
            $unread = $request->query('unread');
            if (!in_array($unread, ['', '0', '1'], true)) {
                throw new DomainException('Choose an available notification filter.', 422);
            }
            return $this->page('feed', 'Notifications', [
                'rows' => $this->inbox->listing($viewer['id'], $before, $unread === '1', $category),
                'unread' => $this->inbox->unread($viewer['id']),
                'category' => $category, 'unreadOnly' => $unread === '1', 'before' => $before,
            ]);
        });
    }

    public function count(Request $request): Response
    {
        return $this->authenticated(fn (array $viewer): Response => Response::json([
            'unread' => $this->inbox->unread($viewer['id']),
        ]));
    }

    public function open(Request $request): Response
    {
        return $this->authenticated(fn (array $viewer): Response => self::redirect(
            $this->inbox->destination($viewer['id'], self::id($request->query('id'))),
        ));
    }

    public function read(Request $request): Response
    {
        return $this->authenticated(function (array $viewer) use ($request): Response {
            $this->checkCsrf($request);
            $this->rejectOwner($request, false);
            if ($request->form('action') === 'read') {
                $this->inbox->markRead($viewer['id'], self::id($request->form('id')));
                $this->session->notice('Notification marked as read.');
            } elseif ($request->form('action') === 'all') {
                $this->inbox->markAll($viewer['id'], self::id($request->form('through')));
                $this->session->notice('Notifications marked as read.');
            } else {
                throw new DomainException('Choose an available notification action.', 422);
            }
            return self::redirect('/notifications');
        });
    }

    public function preferences(Request $request): Response
    {
        return $this->authenticated(function (array $viewer) use ($request): Response {
            if ($request->method === 'POST') {
                $this->checkCsrf($request);
                $this->rejectOwner($request);
                if ($request->form('channels') === 'independent') {
                    $inApp = self::categories($request, 'in_app');
                    $email = self::categories($request, 'email');
                    $this->settings->save($viewer['id'], $inApp, $email);
                } elseif ($request->form('channels') === '' && $request->formArray('channels') === []) {
                    // Existing email-only forms must not change in-app delivery.
                    $email = self::categories($request, 'enabled');
                    $this->settings->saveEmail($viewer['id'], $email);
                } else {
                    throw new DomainException('Reopen notification settings and try again.', 422);
                }
                $this->session->notice('Your notification preferences have been saved.');
                return self::redirect('/settings/notifications');
            }
            return $this->page('preferences', 'Notification settings', [
                'preferences' => $this->settings->forUser($viewer['id']),
            ]);
        });
    }

    private function authenticated(callable $action): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }
        try {
            return $action($viewer);
        } catch (DomainException $error) {
            $title = match ($error->getCode()) {
                403 => 'Access denied',
                404 => 'Notification unavailable',
                409 => 'Notification update conflict',
                422 => 'Check notification details',
                default => throw $error,
            };
            return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
                'message' => $error->getMessage(), 'signedIn' => true, 'csrf' => $this->session->csrf(),
            ]), $error->getCode(), self::HEADERS);
        }
    }

    private function page(string $template, string $title, array $data): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/' . $template . '.php', $title, $data + [
            'signedIn' => true, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
            'path' => $template === 'feed' ? '/notifications' : '/settings/notifications',
            'categories' => NotificationPreferences::CATEGORIES,
            'wat' => static fn (string $timestamp): string => (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Africa/Lagos'))->format('d M Y, H:i'),
        ]), 200, self::HEADERS);
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            throw new DomainException('Reopen the page and try again.', 403);
        }
    }

    private function rejectOwner(Request $request, bool $includeId = true): void
    {
        foreach (['user', 'user_id', 'owner', 'owner_id', 'account_id', ...($includeId ? ['id'] : [])] as $field) {
            if ($request->form($field) !== '' || $request->query($field) !== '' || $request->formArray($field) !== []) {
                throw new DomainException('Notifications can only be changed for your own account.', 422);
            }
        }
    }

    private static function categories(Request $request, string $field): array
    {
        $categories = $request->formArray($field);
        if ($request->form($field) !== '') {
            throw new DomainException('Choose valid notification categories.', 422);
        }
        foreach ($categories as $category) {
            if (!is_string($category) || !isset(NotificationPreferences::CATEGORIES[$category])) {
                throw new DomainException('Choose valid notification categories.', 422);
            }
        }
        return array_values(array_unique($categories));
    }

    private static function id(string $raw): int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? throw new DomainException('Choose an available notification.', 422) : $id;
    }

    private static function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

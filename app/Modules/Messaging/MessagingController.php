<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Messaging;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;

final class MessagingController
{
    public const ROOT = '/messages';
    public const STAFF = '/dashboard/admin/messages';
    public const CONTEXTS = [
        'support' => 'General support',
        'recruitment' => 'Recruitment',
        'vacancy' => 'Vacancy',
        'tutoring' => 'Tutoring request',
        'candidate_information' => 'Candidate information',
        'placement' => 'Placement',
        'training' => 'Training request',
    ];
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly MessageRepository $messages,
    ) {
    }

    public function owner(Request $request): Response
    {
        return $this->handle($request, false);
    }

    public function staff(Request $request): Response
    {
        return $this->handle($request, true);
    }

    public function readOwner(Request $request): Response
    {
        return $this->handle($request, false, true);
    }

    public function readStaff(Request $request): Response
    {
        return $this->handle($request, true, true);
    }

    private function handle(Request $request, bool $staff, bool $readEndpoint = false): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            if ($readEndpoint) {
                return self::json(['error' => 'Please sign in again.'], 401);
            }
            return self::redirect('/login');
        }

        try {
            $roles = array_values(array_filter(
                Role::publicRoles(),
                static fn (Role $role): bool => in_array($role, $viewer['roles'], true),
            ));
            if ($staff && !in_array(Role::Admin, $viewer['roles'], true)) {
                throw new DomainException('An administrator account is required.', 403);
            }
            if (!$staff && $roles === []) {
                throw new DomainException('A teacher, school or parent account is required.', 403);
            }

            $root = $staff ? self::STAFF : self::ROOT;
            if ($request->method === 'POST') {
                if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
                    throw new DomainException('Reopen the page and try again.', 403);
                }
                $action = $readEndpoint ? 'read' : $request->form('action');
                $id = $this->save($request, $viewer, $roles, $staff, $action);
                if ($readEndpoint) {
                    return self::json(['ok' => true]);
                }
                $this->session->notice(
                    $action === 'read' ? 'Displayed messages marked as read.' : 'Conversation updated.',
                );
                return self::redirect($root . '?id=' . $id);
            }

            $common = [
                'viewer' => $viewer['id'],
                'staff' => $staff,
                'root' => $root,
                'roles' => $roles,
                'unread' => $this->messages->unread($viewer['id'], $staff),
            ];
            if ($request->query('id') !== '') {
                return $this->showThread($request, $viewer['id'], $staff, $common);
            }
            return $this->showQueue($request, $viewer['id'], $staff, $common);
        } catch (DomainException $error) {
            if (!in_array($error->getCode(), [403, 404, 409, 422, 429], true)) {
                throw $error;
            }
            if ($readEndpoint) {
                return self::json(['error' => $error->getMessage()], $error->getCode());
            }
            return Response::html($this->view->render(
                dirname(__DIR__) . '/Auth/Views/problem.php',
                'Conversation unavailable',
                [
                    'message' => $error->getMessage(),
                    'signedIn' => true,
                    'csrf' => $this->session->csrf(),
                ],
            ), $error->getCode(), self::HEADERS);
        }
    }

    private function save(Request $request, array $viewer, array $roles, bool $staff, string $action): int
    {
        if ($action === 'create') {
            $role = $roles[0] ?? Role::Teacher;
            if ($request->form('owner_role') !== '') {
                $role = Role::tryFrom($request->form('owner_role'));
            }
            if ($role === null || !in_array($role, Role::publicRoles(), true)
                || (!$staff && !in_array($role, $roles, true))) {
                throw new DomainException('Choose an available account role.', 422);
            }
            $context = $request->form('context_type') ?: 'support';
            if (!array_key_exists($context, self::CONTEXTS)) {
                throw new DomainException('Choose an available conversation topic.', 422);
            }
            $contextId = null;
            if ($request->form('context_id') !== '') {
                $contextId = self::number($request->form('context_id'));
                if ($contextId === null) {
                    throw new DomainException('Enter a valid linked record number.', 422);
                }
            }
            $owner = $staff ? self::requiredId($request->form('owner_id')) : $viewer['id'];
            return $this->messages->create(
                $viewer['id'], $staff, $owner, $role,
                $request->form('subject'), $request->form('body'),
                $context, $contextId, $request->form('token'),
            );
        }

        $id = self::requiredId($request->form('id'));
        if ($action === 'send') {
            $this->messages->send($viewer['id'], $staff, $id, $request->form('body'), $request->form('token'));
        } elseif ($action === 'read') {
            $last = self::number($request->form('last_id'))
                ?? throw new DomainException('Reopen the conversation before marking it read.', 422);
            $this->messages->read($viewer['id'], $staff, $id, $last);
        } elseif ($staff && in_array($action, ['assign', 'status', 'hold'], true)) {
            $revision = self::number($request->form('revision'), 0)
                ?? throw new DomainException('Reopen the conversation to get the current revision.', 409);
            $value = $action === 'assign' ? '' : $request->form($action);
            $this->messages->manage($viewer['id'], $id, $revision, $action, $value);
        } else {
            throw new DomainException('Choose an available conversation action.', 422);
        }
        return $id;
    }

    private function showThread(Request $request, int $actor, bool $staff, array $common): Response
    {
        $id = self::requiredId($request->query('id'));
        $thread = $this->messages->thread($actor, $staff, $id);
        $before = self::number($request->query('before')) ?? PHP_INT_MAX;
        $rows = $this->messages->messages($actor, $staff, $id, $before);
        $next = count($rows) > 30 ? (int) $rows[29]['id'] : null;
        $rows = array_reverse(array_slice($rows, 0, 30));
        $last = $rows === [] ? 0 : (int) $rows[count($rows) - 1]['id'];

        return $this->page('detail', $thread['subject'], $common + [
            'thread' => $thread,
            'messages' => $rows,
            'next' => $next,
            'last' => $last,
        ]);
    }

    private function showQueue(Request $request, int $actor, bool $staff, array $common): Response
    {
        $filters = [];
        if ($staff) {
            $filters = [
                'q' => trim($request->query('q')),
                'status' => $request->query('status') ?: 'all',
                'assigned' => $request->query('assigned') ?: 'all',
                'context_type' => $request->query('context_type') ?: 'all',
            ];
        }
        $before = self::number($request->query('before')) ?? PHP_INT_MAX;
        $rows = $this->messages->threads($actor, $staff, $before, $filters);
        $next = count($rows) > 20 ? (int) $rows[19]['id'] : null;
        $context = $request->query('context_type');
        if (!array_key_exists($context, self::CONTEXTS)) {
            $context = 'support';
        }

        return $this->page('queue', $staff ? 'Managed conversations' : 'My conversations', $common + [
            'threads' => array_slice($rows, 0, 20),
            'next' => $next,
            'filters' => $filters,
            'new' => $request->query('new') === '1',
            'context_type' => $context,
            'context_id' => self::number($request->query('context_id')),
            'newOwner' => $staff ? self::number($request->query('owner_id')) : null,
            'newRole' => $staff ? $request->query('owner_role') : '',
        ]);
    }

    private function page(string $template, string $title, array $data): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/' . $template . '.php', $title, $data + [
            'notice' => $this->session->takeNotice(),
            'csrf' => $this->session->csrf(),
            'signedIn' => true,
            'token' => bin2hex(random_bytes(16)),
            'contexts' => self::CONTEXTS,
        ]), 200, self::HEADERS);
    }

    private static function number(string $value, int $minimum = 1): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
        return $number === false ? null : $number;
    }

    private static function requiredId(string $value): int
    {
        return self::number($value) ?? throw new DomainException('This conversation could not be found.', 404);
    }

    private static function json(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data, JSON_THROW_ON_ERROR), $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ] + self::HEADERS);
    }

    private static function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

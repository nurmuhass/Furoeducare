<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Placement;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Directory\DirectoryFilters;

final class PlacementController
{
    public const ROOT = '/placements';
    public const STAFF = '/dashboard/admin/placements';

    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly PlacementRepository $placements,
    ) {
    }

    public function staff(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }

        try {
            if (!in_array(Role::Admin, $viewer['roles'], true)) {
                throw new DomainException('An administrator account is required.', 403);
            }
            if ($request->method === 'POST') {
                $this->checkCsrf($request);
                $id = $this->saveStaff($request, $viewer['id']);
                return self::redirect(self::STAFF . '?id=' . $id);
            }
            if ($request->query('id') !== '') {
                $id = self::requiredId($request->query('id'));
                $placement = $this->placements->staffDetail($id) ?? throw self::missing();
                return $this->page('detail', 'Placement #' . $id, $viewer['id'], true, ['placement' => $placement]);
            }

            $before = self::number($request->query('before')) ?? PHP_INT_MAX;
            return $this->queue($this->placements->staffList($before), $viewer['id'], true, [
                'application' => self::number($request->query('application_id')),
                'tutoring' => self::number($request->query('tutoring_id')),
                'reference' => DirectoryFilters::reference($request->query('reference')),
                'mode' => $request->query('mode'),
            ]);
        } catch (DomainException $error) {
            return $this->problem($error);
        }
    }

    public function participant(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }

        try {
            $id = $viewer['id'];
            $roles = $viewer['roles'];
            if (!self::hasParticipantRole($roles)) {
                throw new DomainException('A teacher, school or parent account is required.', 403);
            }
            if ($request->method === 'POST') {
                $this->checkCsrf($request);
                if (!in_array(Role::School, $roles, true) && !in_array(Role::Parent, $roles, true)) {
                    throw new DomainException('The requesting school or parent must make the selection.', 403);
                }
                if ($request->form('action') !== 'select') {
                    throw new DomainException('Choose an available placement action.', 422);
                }
                $placementId = self::requiredId($request->form('id'));
                $this->placements->select($id, $placementId, self::revision($request));
                $this->session->notice('Selection recorded. Furoeducare will review the remaining placement steps.');
                return self::redirect(self::ROOT . '?id=' . $placementId);
            }
            if ($request->query('id') !== '') {
                $placementId = self::requiredId($request->query('id'));
                $placement = $this->placements->participantDetail($id, $roles, $placementId) ?? throw self::missing();
                return $this->page('detail', 'Placement #' . $placementId, $id, false, ['placement' => $placement]);
            }

            $before = self::number($request->query('before')) ?? PHP_INT_MAX;
            return $this->queue($this->placements->participantList($id, $roles, $before), $id, false);
        } catch (DomainException $error) {
            return $this->problem($error);
        }
    }

    private function saveStaff(Request $request, int $staff): int
    {
        $action = $request->form('action');
        if ($action === 'start_employment') {
            $id = $this->placements->startEmployment($staff, self::requiredId($request->form('application_id')));
        } elseif ($action === 'start_tutoring') {
            $reference = DirectoryFilters::reference($request->form('reference'));
            $mode = $request->form('mode');
            if ($reference === null || !in_array($mode, ['home', 'online'], true)) {
                throw new DomainException('Enter a valid shortlisted tutor reference and choose a lesson format.', 422);
            }
            $id = $this->placements->startTutoring($staff, self::requiredId($request->form('tutoring_id')), $reference, $mode);
        } else {
            $id = self::requiredId($request->form('id'));
            $this->placements->manage($staff, $id, self::revision($request), $action, self::actionInput($request, $action));
        }
        $this->session->notice('Placement updated.');
        return $id;
    }

    private static function actionInput(Request $request, string $action): array
    {
        if ($action === 'assign') {
            return [];
        }
        $fields = match ($action) {
            'shortlist', 'available', 'select', 'confirm', 'reject', 'close' => [],
            'schedule' => ['scheduled_at' => 16],
            'offer' => ['offer_summary' => 1000, 'starts_on' => 10],
            'safeguard' => ['safeguarding_reference' => 120] + array_fill_keys(PlacementInput::HOME_CHECKS, 1),
            default => throw new DomainException('Choose an available placement action.', 422),
        };
        $input = [];
        foreach (['note' => 1000] + $fields as $field => $limit) {
            $value = trim(str_replace(["\r\n", "\r"], "\n", $request->form($field)));
            if (preg_match('/\A.{0,' . $limit . '}\z/us', $value) !== 1 || preg_match('/[^\P{Cc}\n\t]/u', $value) !== 0) {
                throw new DomainException('Check the placement fields and try again.', 422);
            }
            $input[$field] = $value;
        }
        return $input;
    }

    private static function hasParticipantRole(array $roles): bool
    {
        foreach (Role::publicRoles() as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }
        return false;
    }

    private function queue(array $rows, int $viewer, bool $staff, array $source = []): Response
    {
        $next = count($rows) > self::PAGE_SIZE ? (int) $rows[self::PAGE_SIZE - 1]['id'] : null;
        return $this->page('queue', $staff ? 'Managed placements' : 'My placements', $viewer, $staff, [
            'placements' => array_slice($rows, 0, self::PAGE_SIZE),
            'next' => $next,
            'source' => $source,
        ]);
    }

    private function page(string $template, string $title, int $viewer, bool $staff, array $data): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/' . $template . '.php', $title, $data + [
            'viewer' => $viewer,
            'staff' => $staff,
            'root' => $staff ? self::STAFF : self::ROOT,
            'notice' => $this->session->takeNotice(),
            'csrf' => $this->session->csrf(),
            'signedIn' => true,
        ]), 200, self::HEADERS);
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            throw new DomainException('Reopen the page and try again.', 403);
        }
    }

    private static function revision(Request $request): int
    {
        return self::number($request->form('revision'), 0)
            ?? throw new DomainException('Reopen the placement to get the current revision.', 409);
    }

    private static function requiredId(string $value): int
    {
        return self::number($value) ?? throw self::missing();
    }

    private static function number(string $value, int $minimum = 1): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
        return $number === false ? null : $number;
    }

    private static function missing(): DomainException
    {
        return new DomainException('This placement could not be found.', 404);
    }

    private function problem(DomainException $error): Response
    {
        $title = match ($error->getCode()) {
            403 => 'Access denied',
            404 => 'Placement unavailable',
            409 => 'Placement update unavailable',
            422 => 'Check your placement details',
            default => throw $error,
        };
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
            'message' => $error->getMessage(), 'signedIn' => true, 'csrf' => $this->session->csrf(),
        ]), $error->getCode(), self::HEADERS);
    }

    private static function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Training;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;

final class TrainingController
{
    public const ROOT = '/training-workshops';
    public const SCHOOL = '/dashboard/school/training';
    public const STAFF = '/dashboard/admin/training';
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
    private const MODULE_FIELDS = [
        'title', 'description', 'category', 'objectives', 'target_participants', 'duration',
        'delivery_format', 'prerequisites', 'materials_metadata',
    ];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly TrainingCatalogue $catalogue,
        private readonly TrainingRequests $requests,
    ) {
    }

    public function catalogue(Request $request): Response
    {
        $viewer = $this->access->viewer();
        try {
            $rows = $this->catalogue->browse(
                trim($request->query('q')), self::before($request, 'modules_before'), self::before($request, 'packages_before'),
            );
            return $this->page('catalogue', 'Training and workshops', $viewer, $rows + [
                'staff' => false, 'q' => $request->query('q'),
                'cursors' => ['modules_before' => $request->query('modules_before'), 'packages_before' => $request->query('packages_before')],
            ]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    public function module(Request $request): Response
    {
        return $this->publicDetail($request, 'module');
    }

    public function package(Request $request): Response
    {
        return $this->publicDetail($request, 'package');
    }

    private function publicDetail(Request $request, string $kind): Response
    {
        $viewer = $this->access->viewer();
        try {
            $item = $this->catalogue->{$kind}(self::id($request->query('id'))) ?? throw self::missing();
            return $this->page('detail', $item['title'], $viewer, ['item' => $item, 'kind' => $kind]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    public function school(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }
        try {
            self::role($viewer, Role::School);
            if ($request->method === 'POST') {
                $this->checkCsrf($request);
                $values = self::fields($request, ['participants', 'location', 'preferred_dates', 'requirements', 'package_id', 'client_token']);
                $values['module_ids'] = $request->formArray('module_ids');
                try {
                    $id = $this->requests->submit(
                        $viewer['id'], $values, $values['module_ids'],
                        $values['package_id'] === '' ? null : self::id($values['package_id']), $values['client_token'],
                    );
                    $this->session->notice('Training request submitted. Management will review your requirements.');
                    return self::redirect(self::SCHOOL . '?id=' . $id);
                } catch (DomainException $error) {
                    if ($error->getCode() !== 422) {
                        throw $error;
                    }
                    return $this->newRequest($viewer, $values, $error->getMessage(), 422);
                }
            }
            if ($request->query('new') === '1') {
                return $this->newRequest($viewer, [
                    'module_ids' => $request->query('module_id') === '' ? [] : [$request->query('module_id')],
                    'package_id' => $request->query('package_id'), 'client_token' => bin2hex(random_bytes(16)),
                ]);
            }
            if ($request->query('id') !== '') {
                return $this->requestDetail($viewer, false, self::id($request->query('id')));
            }
            $rows = $this->requests->forSchool($viewer['id'], self::before($request));
            return $this->page('requests', 'My training requests', $viewer, ['rows' => $rows, 'staff' => false, 'filters' => []]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    private function newRequest(array $viewer, array $values, string $error = '', int $status = 200): Response
    {
        return $this->page('request-form', 'Request institutional training', $viewer, [
            'choices' => $this->catalogue->choices($values['module_ids'] ?? [], $values['package_id'] ?? null),
            'values' => $values, 'error' => $error,
        ], $status);
    }

    public function staff(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }
        try {
            self::role($viewer, Role::Admin);
            $action = $request->form('action');
            $kind = ($request->method === 'POST' ? $request->form('kind') : $request->query('kind')) ?: 'module';
            if (!in_array($kind, ['module', 'package'], true)) {
                throw new DomainException('Choose a module or package.', 422);
            }
            $rawId = $request->method === 'POST' ? $request->form('id') : $request->query('id');
            $id = $rawId === '' ? null : self::id($rawId);
            if ($request->method === 'POST') {
                $this->checkCsrf($request);
                $revision = $id === null ? null : self::revision($request);
                $values = self::fields($request, $kind === 'module' ? self::MODULE_FIELDS : ['title', 'description']);
                $values['module_ids'] = $request->formArray('module_ids');
                try {
                    if ($kind === 'package' && $action === 'save') {
                        $positions = $request->formArray('module_order');
                        if ($positions !== []) {
                            $used = [];
                            foreach ($values['module_ids'] as $moduleId) {
                                $position = is_scalar($moduleId) ? ($positions[(string) $moduleId] ?? '') : '';
                                if (!is_string($position) || !ctype_digit($position) || (int) $position < 1 || (int) $position > 20 || isset($used[(int) $position])) {
                                    throw new DomainException('Give every selected module a unique position from 1 to 20.', 422);
                                }
                                $used[(int) $position] = $moduleId;
                            }
                            ksort($used);
                            $values['module_ids'] = array_values($used);
                        }
                    }
                    if ($action === 'save') {
                        $id = $kind === 'module'
                            ? $this->catalogue->saveModule($viewer['id'], $id, $revision, $values)
                            : $this->catalogue->savePackage($viewer['id'], $id, $revision, $values, $values['module_ids']);
                    } elseif ($action === 'publish' && $id !== null) {
                        $this->catalogue->publish($viewer['id'], $kind, $id, $revision, $request->form('status'));
                    } else {
                        throw new DomainException('Choose an available catalogue action.', 422);
                    }
                } catch (DomainException $error) {
                    if ($error->getCode() !== 422) {
                        throw $error;
                    }
                    return $this->manage($viewer, $kind, $id, $action === 'save' ? $values : null, $error->getMessage(), 422);
                }
                $this->session->notice('Training catalogue updated.');
                return self::redirect(self::STAFF . '?kind=' . $kind . '&id=' . $id);
            }
            if ($request->query('new') === '1' || $id !== null) {
                return $this->manage($viewer, $kind, $id);
            }
            return $this->page('catalogue', 'Manage training catalogue', $viewer, [
                'modules' => $this->catalogue->staffModules(self::before($request, 'modules_before')),
                'packages' => $this->catalogue->staffPackages(self::before($request, 'packages_before')), 'staff' => true, 'q' => '',
                'cursors' => ['modules_before' => $request->query('modules_before'), 'packages_before' => $request->query('packages_before')],
            ]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    private function manage(array $viewer, string $kind, ?int $id, ?array $values = null, string $error = '', int $status = 200): Response
    {
        $item = $id === null ? [] : ($this->catalogue->{$kind}($id, true) ?? throw self::missing());
        // Package editors must include unpublished modules, including existing members.
        $modules = [];
        if ($kind === 'package') {
            $before = PHP_INT_MAX;
            do {
                $batch = $this->catalogue->staffModules($before);
                $page = array_slice($batch, 0, 20);
                array_push($modules, ...$page);
                $before = $page === [] ? 0 : (int) $page[count($page) - 1]['id'];
            } while (count($batch) > 20);
        }
        return $this->page('manage', ($id === null ? 'Create ' : 'Manage ') . $kind, $viewer, compact('kind', 'item', 'values', 'error', 'modules'), $status);
    }

    public function staffRequests(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }
        try {
            self::role($viewer, Role::Admin);
            if ($request->method === 'POST') {
                $this->checkCsrf($request);
                $id = self::id($request->form('id'));
                $fields = self::fields($request, ['assigned_admin_id', 'note', 'starts_at', 'ends_at', 'delivery_format', 'session_location', 'outcome']);
                try {
                    $this->requests->change($viewer['id'], $id, self::revision($request), $request->form('action'), $fields);
                } catch (DomainException $error) {
                    if ($error->getCode() !== 422) {
                        throw $error;
                    }
                    return $this->requestDetail($viewer, true, $id, $error->getMessage(), $fields, 422);
                }
                $this->session->notice('Training request updated.');
                return self::redirect(self::STAFF . '/requests?id=' . $id);
            }
            if ($request->query('id') !== '') {
                return $this->requestDetail($viewer, true, self::id($request->query('id')));
            }
            $filters = [];
            foreach (['status', 'assigned_admin_id', 'q'] as $field) {
                $filters[$field] = $request->query($field);
            }
            $rows = $this->requests->forStaff($filters, self::before($request));
            return $this->page('requests', 'Manage training requests', $viewer, ['rows' => $rows, 'staff' => true, 'filters' => $filters, 'admins' => $this->requests->staffChoices()]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    private function requestDetail(array $viewer, bool $staff, int $id, string $error = '', array $values = [], int $status = 200): Response
    {
        $item = $this->requests->find($viewer['id'], $staff, $id) ?? throw self::missing();
        return $this->page('request-detail', 'Training request ' . $item['reference'], $viewer, ['item' => $item, 'staff' => $staff, 'admins' => $staff ? $this->requests->staffChoices() : [], 'error' => $error, 'values' => $values], $status);
    }

    private function page(string $template, string $title, ?array $viewer, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/' . $template . '.php', $title, $data + [
            'viewer' => $viewer, 'signedIn' => $viewer !== null, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
            'wat' => static fn (string $timestamp): string => (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('d M Y, H:i'),
        ]), $status, self::HEADERS);
    }

    private static function fields(Request $request, array $names): array
    {
        $fields = [];
        foreach ($names as $name) {
            $fields[$name] = $request->form($name);
        }
        return $fields;
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            throw new DomainException('Reopen the page and try again.', 403);
        }
    }

    private static function role(array $viewer, Role $role): void
    {
        if (!in_array($role, $viewer['roles'], true)) {
            throw new DomainException('This page requires a ' . strtolower($role->label()) . ' account.', 403);
        }
    }

    private static function id(string $raw): int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? throw self::missing() : $id;
    }

    private static function revision(Request $request): int
    {
        $revision = filter_var($request->form('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $revision === false ? throw new DomainException('Reopen the page to get the current revision.', 409) : $revision;
    }

    private static function before(Request $request, string $name = 'before'): int
    {
        return $request->query($name) === '' ? PHP_INT_MAX : self::id($request->query($name));
    }

    private static function missing(): DomainException
    {
        return new DomainException('This training record is unavailable.', 404);
    }

    private function problem(DomainException $error, ?array $viewer): Response
    {
        $title = match ($error->getCode()) {
            403 => 'Access denied',
            404 => 'Training unavailable',
            409 => 'Training update conflict',
            422 => 'Check the training details',
            default => throw $error,
        };
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, ['message' => $error->getMessage(), 'signedIn' => $viewer !== null, 'csrf' => $this->session->csrf()]), $error->getCode(), self::HEADERS);
    }
    private static function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

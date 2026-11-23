<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Library;

use DomainException;
use DateTimeImmutable;
use DateTimeZone;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use InvalidArgumentException;

final class LibraryController
{
    public const ROOT = '/digital-library';
    public const STAFF = '/dashboard/admin/library';
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
    private const FIELDS = [
        'title', 'author', 'description', 'publisher', 'publication_year', 'edition', 'isbn', 'access',
        'licence', 'rights_reference', 'rights_expires_on', 'rights_confirmed', 'categories', 'subjects', 'levels',
    ];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly LibraryRepository $library,
        private readonly LibraryService $service,
    ) {
    }

    public function catalogue(Request $request): Response
    {
        return $this->listing($request, false);
    }

    public function saved(Request $request): Response
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $saved): Response
    {
        $viewer = $this->access->viewer();
        if ($saved && $viewer === null) {
            return self::redirect('/login');
        }
        try {
            $filters = [];
            foreach (['q', 'category', 'subject', 'level'] as $key) {
                $filters[$key] = trim($request->query($key));
            }
            $rows = $this->library->search($filters, self::before($request), false, $viewer['id'] ?? null, $saved);
            return $this->page('catalogue', $saved ? 'Saved resources' : 'Digital library', $viewer, [
                'rows' => array_slice($rows, 0, 12), 'next' => count($rows) > 12 ? (int) $rows[11]['id'] : null,
                'filters' => $filters, 'saved' => $saved, 'staff' => false,
                'root' => self::ROOT . ($saved ? '/saved' : ''),
            ]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    public function resource(Request $request): Response
    {
        $viewer = $this->access->viewer();
        try {
            $resource = $this->library->publicResource(self::reference($request->query('ref'))) ?? throw self::missing();
            $resource['grant_expires_at'] = $this->service->grantExpiry($resource, $viewer);
            return $this->page('resource', $resource['title'], $viewer, [
                'resource' => $resource, 'canDownload' => $this->service->canDownload($resource, $viewer),
            ]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    public function bookmark(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }
        try {
            $this->checkCsrf($request);
            $ref = self::reference($request->form('ref'));
            $action = $request->form('action');
            if (!in_array($action, ['save', 'remove'], true)) {
                throw new DomainException('Choose a saved-resource action.', 422);
            }
            $this->library->bookmark($viewer['id'], $ref, $action === 'save');
            $this->session->notice($action === 'save' ? 'Resource saved to your account.' : 'Resource removed from your saved list.');
            return self::redirect($action === 'remove' ? self::ROOT . '/saved' : self::ROOT . '/resource?ref=' . rawurlencode($ref));
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    public function history(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }
        try {
            $rows = $this->library->history($viewer['id'], self::before($request));
            return $this->page('history', 'My download history', $viewer, [
                'rows' => array_slice($rows, 0, 20), 'next' => count($rows) > 20 ? (int) $rows[19]['id'] : null,
            ]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    public function download(Request $request): Response
    {
        $viewer = $this->access->viewer();
        try {
            return $this->service->download(self::reference($request->query('ref')), $viewer, $request->method === 'HEAD');
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    public function cover(Request $request): Response
    {
        try {
            return $this->service->cover(self::reference($request->query('ref')), $request->method === 'HEAD');
        } catch (DomainException $error) {
            return $this->problem($error, null);
        }
    }

    public function preview(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }
        try {
            self::admin($viewer);
            return $this->service->preview($viewer['id'], self::id($request->query('id')), $request->method === 'HEAD');
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    public function staff(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return self::redirect('/login');
        }
        try {
            self::admin($viewer);
            if ($request->method === 'POST') {
                $result = $this->saveStaff($request, $viewer);
                if ($result instanceof Response) {
                    return $result;
                }
                $id = $result;
                $this->session->notice('Library resource updated.');
                return self::redirect(self::STAFF . '?id=' . $id);
            }
            if ($request->query('new') === '1' || $request->query('id') !== '') {
                $grantsBefore = $request->query('grants_before') === '' ? PHP_INT_MAX : self::id($request->query('grants_before'));
                $resource = $request->query('id') === '' ? [] : ($this->library->staffResource(self::id($request->query('id')), $grantsBefore) ?? throw self::missing());
                return $this->page('manage', $resource === [] ? 'Add library resource' : 'Manage library resource', $viewer, ['resource' => $resource, 'values' => null, 'error' => '']);
            }
            $filters = [];
            foreach (['q', 'category', 'subject', 'level'] as $key) {
                $filters[$key] = trim($request->query($key));
            }
            $rows = $this->library->search($filters, self::before($request), true);
            return $this->page('catalogue', 'Manage digital library', $viewer, [
                'rows' => array_slice($rows, 0, 20), 'next' => count($rows) > 20 ? (int) $rows[19]['id'] : null,
                'filters' => $filters, 'saved' => false, 'staff' => true, 'root' => self::STAFF,
            ]);
        } catch (DomainException $error) {
            return $this->problem($error, $viewer);
        }
    }

    private function saveStaff(Request $request, array $viewer): int|Response
    {
        $this->checkCsrf($request);
        $action = $request->form('action');
        $id = $request->form('id') === '' ? null : self::id($request->form('id'));
        $revision = $id === null ? null : self::revision($request);
        if ($action === 'save') {
            $fields = [];
            foreach (self::FIELDS as $field) {
                $fields[$field] = $request->form($field);
            }
            try {
                $id = $this->library->save(
                    $viewer['id'], $id, $revision, $fields,
                    $request->uploadedFile('material'), $request->uploadedFile('cover'),
                );
            } catch (DomainException|InvalidArgumentException $error) {
                if ($error instanceof DomainException && $error->getCode() !== 422) {
                    throw $error;
                }
                $resource = $id === null ? [] : ($this->library->staffResource($id) ?? throw self::missing());
                return $this->page(
                    'manage', $id === null ? 'Add library resource' : 'Manage library resource', $viewer,
                    ['resource' => $resource, 'values' => $fields, 'error' => $error->getMessage()], 422,
                );
            }
        } else {
            if ($id === null) {
                throw self::missing();
            }
            if ($action === 'grant') {
                $this->library->grant(
                    $viewer['id'], $id, $revision, self::id($request->form('recipient_id')),
                    $request->form('expires_at'), $request->form('reason'),
                );
            } elseif ($action === 'revoke') {
                $this->library->revoke(
                    $viewer['id'], $id, $revision, self::id($request->form('grant_id')), $request->form('reason'),
                );
            } elseif (in_array($action, ['publish', 'unpublish', 'archive', 'remove'], true)) {
                $this->library->transition($viewer['id'], $id, $revision, $action);
            } else {
                throw new DomainException('Choose an available library action.', 422);
            }
        }
        return $id;
    }

    private function page(string $template, string $title, ?array $viewer, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/' . $template . '.php', $title, $data + [
            'viewer' => $viewer, 'signedIn' => $viewer !== null, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
            'wat' => static fn (string $timestamp): string => (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Africa/Lagos'))->format('d M Y, H:i'),
        ]), $status, self::HEADERS);
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            throw new DomainException('Reopen the page and try again.', 403);
        }
    }

    private static function admin(array $viewer): void
    {
        if (!in_array(Role::Admin, $viewer['roles'], true)) {
            throw new DomainException('An administrator account is required.', 403);
        }
    }

    private static function before(Request $request): int
    {
        return $request->query('before') === '' ? PHP_INT_MAX : self::id($request->query('before'));
    }

    private static function revision(Request $request): int
    {
        $value = filter_var($request->form('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $value === false ? throw new DomainException('Reopen the resource to get its current revision.', 409) : $value;
    }

    private static function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? throw self::missing() : $id;
    }

    private static function reference(string $value): string
    {
        return preg_match('/\ALB-[A-F0-9]{32}\z/D', $value) === 1 ? $value : throw self::missing();
    }

    private static function missing(): DomainException
    {
        return new DomainException('This resource is unavailable.', 404);
    }

    private function problem(DomainException $error, ?array $viewer): Response
    {
        $title = match ($error->getCode()) {
            403 => 'Access denied',
            404 => 'Resource unavailable',
            409 => 'Resource update unavailable',
            422 => 'Check the resource details',
            default => throw $error,
        };
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
            'message' => $error->getMessage(), 'signedIn' => $viewer !== null, 'csrf' => $this->session->csrf(),
        ]), $error->getCode(), self::HEADERS);
    }

    private static function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

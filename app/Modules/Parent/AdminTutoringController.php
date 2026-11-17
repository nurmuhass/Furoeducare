<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Parent;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Directory\DirectoryFilters;
use Furoeducare\Modules\Directory\PublicDirectory;

final class AdminTutoringController
{
    public const ROOT = '/dashboard/admin/tutoring-requests';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly TutoringRepository $tutoring,
        private readonly PublicDirectory $directory,
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if (!in_array(Role::Admin, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'An administrator account is required.', 403);
        }
        if ($request->method === 'POST') {
            if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
                return $this->problem('Form expired', 'Reopen the tutoring request and try again.', 403);
            }
            return $this->save($request, $viewer['id']);
        }

        $rawId = $request->query('id');
        if ($rawId === '') {
            return $this->queue($request);
        }
        $id = self::positiveInt($rawId);
        return $id === null ? $this->unavailable() : $this->detail($request, $id);
    }

    private function queue(Request $request): Response
    {
        $selected = TutoringStatus::tryFrom($request->query('status'));
        if ($selected === TutoringStatus::Draft) {
            $selected = null;
        }
        $before = self::positiveInt($request->query('before'));
        $rows = $this->tutoring->staffRequests($before ?? PHP_INT_MAX, $selected);
        $next = count($rows) > self::PAGE_SIZE ? (int) $rows[self::PAGE_SIZE - 1]['id'] : null;
        return $this->page([
            'record' => null, 'rows' => array_slice($rows, 0, self::PAGE_SIZE),
            'selected' => $selected, 'before' => $before, 'next' => $next,
        ]);
    }

    private function detail(Request $request, int $id, string $error = '', array $attempt = []): Response
    {
        $record = $this->tutoring->staffRequest($id);
        if ($record === null || $record['status'] === TutoringStatus::Draft->value) {
            return $this->unavailable();
        }

        $status = TutoringStatus::from($record['status']);
        $candidates = $this->tutoring->candidates($id);
        $history = $this->tutoring->history($id);
        $finder = $status->canManageCandidates() ? $this->findTeachers($request, $record) : null;
        return $this->page(compact('record', 'status', 'candidates', 'history', 'finder', 'error', 'attempt'),
            $error === '' ? 200 : 422);
    }

    private function findTeachers(Request $request, array $record): array
    {
        $filters = array_intersect_key(DirectoryFilters::fromRequest($request), array_flip(['subject', 'level', 'state']));
        $searchExplicit = $request->query('search') === '1' || $filters !== [];
        $formFilters = $filters;
        if (!$searchExplicit) {
            $subject = trim(preg_split('/[,;\n]/', $record['subject'], 2)[0]);
            if ($subject !== 'Requested teacher' && preg_match('/\A[^\p{C}]{1,100}\z/u', $subject) === 1) {
                $filters['subject'] = $subject;
            }
        }
        $after = DirectoryFilters::reference($request->query('after'));
        $before = DirectoryFilters::reference($request->query('before'));
        $modes = TutoringInput::modeMask($record['details']['mode'] ?? '');
        $result = $this->directory->search($filters, $after, $before, requiredModes: $modes);

        $preferred = null;
        if ($record['selected_teacher_reference'] !== null) {
            $listing = $this->directory->find($record['selected_teacher_reference']);
            if ($listing !== null && (((int) $listing['current_modes']) & $modes) !== 0) {
                $preferred = $listing;
            }
        }
        return $result + ['filters' => $formFilters, 'searchExplicit' => $searchExplicit, 'preferred' => $preferred];
    }

    private function save(Request $request, int $actor): Response
    {
        $id = self::positiveInt($request->form('id'));
        if ($id === null) {
            return $this->unavailable();
        }
        $version = $request->form('version');
        if (!TutoringInput::validVersion($version)) {
            return $this->problem('Request update unavailable', 'Reopen the request to review the current version.', 422);
        }

        $action = $request->form('action');
        $reference = DirectoryFilters::reference($request->form('ref'));
        $stage = TutoringStatus::tryFrom($request->form('status'));
        $note = $action === 'transition' ? self::normaliseNote($request->form('note')) : '';
        try {
            switch ($action) {
                case 'add_candidate':
                case 'remove_candidate':
                    if ($reference === null) {
                        throw new DomainException('Choose a teacher using their directory reference.', 422);
                    }
                    $this->tutoring->changeCandidate($actor, $id, $version, $reference, $action === 'add_candidate');
                    break;
                case 'transition':
                    if ($stage === null || $stage === TutoringStatus::Draft) {
                        throw new DomainException('Choose a tutoring request stage.', 422);
                    }
                    if ($note === null) {
                        throw new DomainException('Use up to 1,000 characters and remove invalid characters from the note.', 422);
                    }
                    $this->tutoring->transition($actor, $id, $version, $stage,
                        $stage === TutoringStatus::Confirmed ? $reference : null, $note);
                    break;
                default:
                    throw new DomainException('Choose an action for this tutoring request.', 422);
            }
        } catch (DomainException $error) {
            return match ($error->getCode()) {
                403 => $this->problem('Access denied', $error->getMessage(), 403),
                404 => $this->unavailable(),
                409 => $this->problem('Request update unavailable', $error->getMessage(), 409),
                422 => $this->detail($request, $id, $error->getMessage(), [
                    'stage' => $stage, 'reference' => $reference, 'note' => $note ?? '',
                ]),
                default => throw $error,
            };
        }

        $this->session->notice('Tutoring request updated.');
        return $this->redirect(self::ROOT . '?id=' . $id);
    }

    private static function normaliseNote(string $note): ?string
    {
        $note = str_replace(["\r\n", "\r"], "\n", $note);
        if (preg_match('/\p{C}/u', str_replace(["\n", "\t"], '', $note)) !== 0) {
            return null;
        }
        $note = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $note);
        return $note !== null && preg_match('/\A.{0,1000}\z/us', $note) === 1 ? $note : null;
    }

    private function page(array $data, int $status = 200): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/tutoring-admin.php', 'Tutoring requests', $data + [
            'signedIn' => true, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
        ]), $status, self::HEADERS);
    }

    private function unavailable(): Response
    {
        return $this->problem('Request unavailable', 'This tutoring request could not be found.', 404);
    }

    private function problem(string $title, string $message, int $status): Response
    {
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
            'message' => $message, 'signedIn' => true, 'csrf' => $this->session->csrf(),
        ]), $status, self::HEADERS);
    }

    private function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }

    private static function positiveInt(string $raw): ?int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}

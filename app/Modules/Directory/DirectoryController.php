<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Directory;

use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Disclosure\CandidateController;
use Furoeducare\Modules\Parent\ParentController;
use Furoeducare\Modules\Parent\TutoringInput;

final class DirectoryController
{
    public const ROOT = '/find-teachers';
    public const ADMIN_REQUESTS = '/dashboard/admin/directory/requests';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly PublicDirectory $directory,
        private readonly DirectoryInteractions $interactions,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->browse($request, $this->access->viewer());
    }

    public function profile(Request $request): Response
    {
        $viewer = $this->access->viewer();
        $membership = $viewer === null ? null : $this->interactions->membership($viewer);
        $reference = DirectoryFilters::reference($request->query('ref'));
        $listing = $reference === null ? null : $this->directory->find($reference, $membership === null ? null : $viewer['id']);
        if ($listing === null) {
            return $this->unavailable($viewer);
        }
        return $this->page('profile', $listing['professional_name'] ?? $listing['title'], $viewer, [
            'listing' => $listing, 'canSave' => $membership !== null,
            'parentTutoring' => in_array(Role::Parent, $viewer['roles'] ?? [], true),
        ]);
    }

    public function photograph(Request $request): Response
    {
        $reference = DirectoryFilters::reference($request->query('ref'));
        $photo = $reference === null ? null : $this->directory->photograph($reference);
        if ($photo === null) {
            return Response::text("Not Found\n", 404, self::HEADERS);
        }
        return new Response($photo, 200, self::HEADERS + [
            'Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($photo),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function request(Request $request): Response
    {
        $viewer = $this->access->viewer();
        $submit = $request->method === 'POST';
        if ($submit && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->expired($viewer);
        }
        $reference = DirectoryFilters::reference($submit ? $request->form('ref') : $request->query('ref'));
        $listing = $reference === null ? null : $this->directory->find($reference);
        if ($listing === null) {
            return $this->unavailable($viewer);
        }
        if ($viewer === null) {
            if ($request->method === 'GET') {
                $this->session->rememberTeacherRequest($reference);
            }
            return $this->redirect('/login');
        }

        if (in_array(Role::Parent, $viewer['roles'], true)) {
            if (((int) $listing['current_modes'] & TutoringInput::modeMask('either')) === 0) {
                return $this->problem('Teacher unavailable for tutoring',
                    'Choose an available tutor in Find Teachers or start a general request from your parent dashboard.', 404, $viewer);
            }
            return $this->redirect(ParentController::EDITOR . '&ref=' . $reference);
        }
        $membership = $this->interactions->membership($viewer);
        if ($membership === null) {
            return $this->accountRequired($viewer);
        }
        if ($membership['canSubmit']) {
            return $this->redirect(CandidateController::ROOT . '?new=1&ref=' . $reference);
        }
        $record = $this->interactions->request($viewer['id'], $reference);
        $page = ['listing' => $listing, 'record' => $record, 'canSubmit' => $membership['canSubmit']];
        if (!$submit) {
            return $this->page('request', 'Request this teacher', $viewer, $page);
        }

        $notes = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $request->form('notes'));
        if ($notes === null || preg_match('/\A.{1,1500}\z/us', $notes) !== 1 || preg_match('/[^\P{Cc}\r\n\t]/u', $notes) === 1) {
            return $this->page('request', 'Request this teacher', $viewer, $page + [
                'error' => 'Describe what you need in 1 to 1,500 characters.',
                'notes' => $notes !== null && strlen($notes) <= 6000 ? $notes : '',
            ], 422);
        }

        $result = $this->interactions->saveRequest($viewer['id'], $reference, $notes);
        if ($result === 'missing') {
            return $this->unavailable($viewer);
        }
        if ($result === 'forbidden') {
            return $this->accountRequired($viewer);
        }
        $this->session->notice(match ($result) {
            'draft' => 'Your private draft was saved. You can submit it when your school is approved.',
            'already_submitted' => 'You already submitted a request for this teacher.',
            default => 'Your request was sent to Furoeducare.',
        });
        return $this->redirect(self::ROOT . '/request?ref=' . $reference);
    }

    public function saved(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if ($this->interactions->membership($viewer) === null) {
            return $this->accountRequired($viewer);
        }
        if ($request->method !== 'POST') {
            return $this->browse($request, $viewer, savedOnly: true);
        }
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->expired($viewer);
        }
        $reference = DirectoryFilters::reference($request->form('ref'));
        $action = $request->form('action');
        if ($reference === null || !in_array($action, ['save', 'remove'], true)) {
            return $this->problem('Invalid bookmark', 'Open a teacher listing and try again.', 422, $viewer);
        }
        $result = $this->interactions->saveBookmark($viewer['id'], $reference, $action === 'save');
        if ($result === 'missing') {
            return $this->unavailable($viewer);
        }
        if ($result === 'forbidden') {
            return $this->accountRequired($viewer);
        }
        $this->session->notice($action === 'save' ? 'Teacher saved.' : 'Teacher removed from your saved list.');
        $path = $request->form('from') === 'saved' ? self::ROOT . '/saved' : self::ROOT . '/profile?ref=' . $reference;
        return $this->redirect($path);
    }

    public function myRequests(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        $membership = $this->interactions->membership($viewer);
        if ($membership === null) {
            return $this->accountRequired($viewer);
        }
        if ($membership['role'] === Role::Parent) {
            return $this->redirect(ParentController::REQUESTS);
        }
        if ($membership['canSubmit']) {
            return $this->redirect(CandidateController::ROOT);
        }
        return $this->requestList($request, $viewer, staff: false);
    }

    public function staffRequests(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if (!in_array(Role::Admin, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'An administrator account is required.', 403, $viewer);
        }
        return $this->requestList($request, $viewer, staff: true);
    }

    private function browse(Request $request, ?array $viewer, bool $savedOnly = false): Response
    {
        $membership = $viewer === null ? null : $this->interactions->membership($viewer);
        $filters = DirectoryFilters::fromRequest($request);
        $after = DirectoryFilters::reference($request->query('after'));
        $before = DirectoryFilters::reference($request->query('before'));
        $result = $this->directory->search($filters, $after, $before, $membership === null ? null : $viewer['id'], $savedOnly);
        return $this->page('browse', $savedOnly ? 'Saved teachers' : 'Find teachers', $viewer, $result + [
            'filters' => $filters, 'canSave' => $membership !== null, 'savedOnly' => $savedOnly,
            'parentTutoring' => in_array(Role::Parent, $viewer['roles'] ?? [], true),
            'hasCursor' => $after !== null || $before !== null,
        ]);
    }

    private function requestList(Request $request, array $viewer, bool $staff): Response
    {
        $before = filter_var($request->query('before'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rows = $this->interactions->requests($staff ? null : $viewer['id'], $before === false ? PHP_INT_MAX : $before);
        return $this->page('requests', $staff ? 'Submitted teacher requests' : 'My teacher requests', $viewer, [
            'requests' => array_slice($rows, 0, 30),
            'next' => count($rows) > 30 ? (int) $rows[29]['id'] : null,
            'staff' => $staff,
        ]);
    }

    private function accountRequired(array $viewer): Response
    {
        return $this->problem('Account access required', 'Use an eligible parent, guardian, or school account with a complete profile.',
            403, $viewer);
    }

    private function expired(?array $viewer): Response
    {
        return $this->problem('Form expired', 'Reopen the page and try again.', 403, $viewer);
    }

    private function unavailable(?array $viewer): Response
    {
        return $this->problem('Teacher unavailable', 'This public profile is unavailable. Browse Find Teachers for current listings.',
            404, $viewer);
    }

    private function page(string $template, string $title, ?array $viewer, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/' . $template . '.php', $title,
            $data + $this->layout($viewer)), $status, self::HEADERS);
    }

    private function problem(string $title, string $message, int $status, ?array $viewer): Response
    {
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title,
            ['message' => $message] + $this->layout($viewer)), $status, self::HEADERS);
    }

    private function layout(?array $viewer): array
    {
        return [
            'path' => self::ROOT, 'signedIn' => $viewer !== null,
            'csrf' => $viewer === null ? '' : $this->session->csrf(),
            'notice' => $viewer === null ? '' : $this->session->takeNotice(),
        ];
    }

    private function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

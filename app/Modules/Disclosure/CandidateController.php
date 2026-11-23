<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Disclosure;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Directory\DirectoryFilters;

final class CandidateController
{
    public const ROOT = '/candidate-requests';
    public const STAFF = '/dashboard/admin/candidate-requests';
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly CandidateRepository $cases,
        private readonly DisclosureRepository $disclosures,
        private readonly DisclosurePolicy $policy,
    ) {
    }

    public function owner(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }

        try {
            $role = $this->ownerRole($viewer);
            $save = $request->method === 'POST';
            if ($save) {
                $this->checkCsrf($request);
            }
            $owner = $viewer['id'];
            if (!$this->cases->eligible($owner, $role)) {
                throw new DomainException('An eligible school or parent account is required to view candidate requests.', 403);
            }
            if ($save) {
                return $this->saveOwner($request, $owner, $role);
            }

            $rawId = $request->query('id');
            if ($rawId !== '') {
                $id = $this->requiredId($rawId);
                $case = $this->cases->ownerCase($owner, $role, $id);
                if ($case === null) {
                    throw $this->missing();
                }
                $canLink = $role === Role::School && $case['vacancy_id'] === null && $case['legacy_directory_id'] !== null
                    && in_array($case['status'], ['draft', 'submitted'], true);
                return $this->page('case', 'Candidate request #' . $id, [
                    'case' => $case, 'staff' => false, 'role' => $role,
                    'shortlist' => $this->cases->shortlist($case, false),
                    'disclosures' => $this->disclosures->forCase($id, false),
                    'canLink' => $canLink, 'vacancies' => $canLink ? $this->cases->vacancies($owner) : [],
                ]);
            }

            if ($request->query('new') === '1') {
                if ($role !== Role::School) {
                    throw new DomainException('An approved school account is required to open a recruitment request.', 403);
                }
                return $this->page('new', 'Request candidates', [
                    'role' => $role,
                    'reference' => $this->reference($request->query('ref'), directoryOnly: true),
                    'vacancies' => $this->cases->vacancies($owner),
                ]);
            }

            $before = self::number($request->query('before')) ?? PHP_INT_MAX;
            return $this->queue($this->cases->ownerCases($owner, $role, $before), staff: false, role: $role);
        } catch (DomainException $error) {
            return $this->problem($error, $role ?? null);
        }
    }

    public function admin(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }

        try {
            if (!in_array(Role::Admin, $viewer['roles'], true)) {
                throw new DomainException('An administrator account is required.', 403);
            }
            if ($request->method === 'POST') {
                $this->checkCsrf($request);
                return $this->saveStaff($request, $viewer['id']);
            }

            $rawId = $request->query('id');
            if ($rawId === '') {
                $before = self::number($request->query('before')) ?? PHP_INT_MAX;
                $pendingDisclosure = $request->query('queue') === 'disclosure';
                return $this->queue($this->cases->staffCases($before, $pendingDisclosure), staff: true, pendingDisclosure: $pendingDisclosure);
            }

            $id = $this->requiredId($rawId);
            $case = $this->cases->staffCase($id);
            if ($case === null) {
                throw $this->missing();
            }
            $assignedToViewer = (int) $case['assigned_admin_id'] === $viewer['id'];
            $disclosures = $this->disclosures->forCase($id, true);
            $selection = $request->query('disclosure');
            $selected = null;
            $preview = null;
            if ($selection !== '') {
                if (!$assignedToViewer) {
                    throw new DomainException('Assign the case to yourself before reviewing a disclosure request.', 403);
                }
                $disclosureId = $this->requiredId($selection);
                foreach ($disclosures as $disclosure) {
                    if ((int) $disclosure['id'] === $disclosureId) {
                        $selected = $disclosure;
                        break;
                    }
                }
                if ($selected === null || $selected['status'] !== 'pending') {
                    throw $this->missing();
                }
                $preview = $this->disclosures->preview($id, $disclosureId);
            }

            $canPrepare = $assignedToViewer && $case['status'] === 'under_review';
            return $this->page('case', 'Candidate request #' . $id, [
                'case' => $case, 'staff' => true, 'assignedToViewer' => $assignedToViewer, 'canPrepare' => $canPrepare,
                'shortlist' => $this->cases->shortlist($case, true), 'disclosures' => $disclosures,
                'options' => $canPrepare ? $this->cases->options($case) : [], 'selected' => $selected, 'preview' => $preview,
            ]);
        } catch (DomainException $error) {
            return $this->problem($error, $role ?? null);
        }
    }

    public function download(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        try {
            $role = $this->ownerRole($viewer);
            $this->checkCsrf($request);
            $response = $this->disclosures->download($viewer['id'], $role, $this->requiredId($request->form('id')));
            return new Response($response->body, $response->status, self::HEADERS + $response->headers);
        } catch (DomainException $error) {
            return $this->problem($error, $role ?? null);
        }
    }

    public function profile(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        try {
            $role = $this->ownerRole($viewer);
            $profile = $this->disclosures->professional($viewer['id'], $role, $this->requiredId($request->query('id')));
            return $this->page('profile', 'Approved professional profile', ['profile' => $profile, 'role' => $role]);
        } catch (DomainException $error) {
            return $this->problem($error, $role ?? null);
        }
    }

    private function saveOwner(Request $request, int $owner, Role $role): Response
    {
        $action = $request->form('action');
        if ($action === 'create') {
            if ($role !== Role::School) {
                throw new DomainException('Start a tutoring request from your parent dashboard.', 403);
            }
            $id = $this->cases->create($owner, $role, self::number($request->form('vacancy_id')) ?? 0,
                $this->reference($request->form('ref'), directoryOnly: true),
                $this->formText($request, 'purpose', 'a purpose', 250),
                $this->formText($request, 'requirements', 'your requirements', 2000));
            $message = 'Candidate request submitted.';
        } else {
            $id = $this->requiredId($request->form('id'));
            switch ($action) {
                case 'link':
                    if ($role !== Role::School) {
                        throw new DomainException('Only school accounts can link a vacancy.', 403);
                    }
                    $this->cases->link($owner, $role, $id, self::number($request->form('vacancy_id')) ?? 0,
                        $this->formText($request, 'purpose', 'a purpose', 250, optional: true),
                        $this->formText($request, 'requirements', 'your requirements', 2000, optional: true));
                    $message = 'Vacancy linked to the candidate request.';
                    break;
                case 'request':
                    $reference = $this->reference($request->form('ref'));
                    if ($reference === null) {
                        throw new DomainException('Choose a candidate from this request.', 422);
                    }
                    $this->disclosures->request($owner, $role, $id, $reference, $request->form('scope'),
                        $request->form('ack') === '1', $request->form('notice_version'));
                    $message = 'Request for access sent to Furoeducare.';
                    break;
                default:
                    throw new DomainException('Choose an action for this candidate request.', 422);
            }
        }
        $this->session->notice($message);
        return $this->redirect(self::ROOT . '?id=' . $id);
    }

    private function saveStaff(Request $request, int $admin): Response
    {
        $id = $this->requiredId($request->form('id'));
        $action = $request->form('action');
        switch ($action) {
            case 'claim':
            case 'add':
            case 'publish':
            case 'close':
                $reference = $action === 'add' ? $this->reference($request->form('ref')) : null;
                $this->cases->manage($admin, $id, $action, $reference);
                $message = 'Candidate request updated.';
                break;
            case 'decide':
                $this->disclosures->decide($admin, $id, $this->requiredId($request->form('disclosure_id')),
                    $request->form('decision'), $this->formText($request, 'reason', 'a decision reason', 1000),
                    trim($request->form('evidence')),
                    self::number($request->form('hours')) ?? 0, $request->form('review_ok') === '1',
                    self::number($request->form('document_id')), self::number($request->form('profile_revision'), 0),
                    $request->form('notice_version'));
                $message = 'Disclosure decision recorded.';
                break;
            case 'revoke':
                $this->disclosures->revoke($admin, $id, $this->requiredId($request->form('grant_id')),
                    $this->formText($request, 'reason', 'a revocation reason', 1000));
                $message = 'Disclosure access revoked.';
                break;
            default:
                throw new DomainException('Choose an action for this candidate request.', 422);
        }
        $this->session->notice($message);
        return $this->redirect(self::STAFF . '?id=' . $id);
    }

    private function ownerRole(array $viewer): Role
    {
        foreach ([Role::Parent, Role::School] as $role) {
            if (in_array($role, $viewer['roles'], true)) {
                return $role;
            }
        }
        throw new DomainException('A school or parent account is required.', 403);
    }

    private function queue(array $cases, bool $staff, ?Role $role = null, bool $pendingDisclosure = false): Response
    {
        $next = count($cases) > self::PAGE_SIZE ? (int) $cases[self::PAGE_SIZE - 1]['id'] : null;
        return $this->page('queue', $staff ? 'Candidate requests' : 'My candidate requests', [
            'cases' => array_slice($cases, 0, self::PAGE_SIZE), 'next' => $next,
            'staff' => $staff, 'role' => $role, 'pendingDisclosure' => $pendingDisclosure,
        ]);
    }

    private function page(string $template, string $title, array $data = []): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/candidate-' . $template . '.php', $title, $data + [
            'signedIn' => true, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
            'root' => self::ROOT, 'staffRoot' => self::STAFF, 'policy' => $this->policy,
        ]), 200, self::HEADERS);
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            throw new DomainException('Reopen the page and try again.', 403);
        }
    }

    private function reference(string $value, bool $directoryOnly = false): ?string
    {
        $reference = $directoryOnly ? DirectoryFilters::reference($value) : CandidateRepository::reference($value);
        if ($value !== '' && $reference === null) {
            throw new DomainException('Choose a valid candidate reference.', 422);
        }
        return $reference;
    }

    private function formText(Request $request, string $field, string $label, int $limit, bool $optional = false): string
    {
        $value = $request->form($field);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
        $minimum = $optional ? 0 : 1;
        if ($value === null || preg_match('/[^\P{Cc}\n\t]/u', $value) !== 0
            || preg_match('/\A.{' . $minimum . ',' . $limit . '}\z/us', $value) !== 1) {
            throw new DomainException('Enter ' . $label . ' of up to ' . $limit . ' characters.', 422);
        }
        return $value;
    }

    private function requiredId(string $value): int
    {
        return self::number($value) ?? throw $this->missing();
    }

    private static function number(string $value, int $minimum = 1): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
        return $number === false ? null : $number;
    }

    private function missing(): DomainException
    {
        return new DomainException('This candidate request or disclosure could not be found.', 404);
    }

    private function problem(DomainException $error, ?Role $role = null): Response
    {
        $title = match ($error->getCode()) {
            403 => 'Access denied',
            404 => 'Request unavailable',
            409 => 'Request changed',
            422 => 'Check your request',
            default => throw $error,
        };
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
              'message' => $error->getMessage(), 'signedIn' => true, 'csrf' => $this->session->csrf(), 'role' => $role,
        ]), $error->getCode(), self::HEADERS);
    }

    private function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\School;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Verification\ReviewInput;
use InvalidArgumentException;

final class SchoolController
{
    private const DASHBOARD = '/dashboard/school';
    private const REVIEW = '/dashboard/admin/schools';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
    private const TABS = ['overview' => 'Overview', 'profile' => 'School profile', 'drafts' => 'Recruitment drafts',
        'notifications' => 'Notifications', 'settings' => 'Settings'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly SchoolRepository $schools,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if (!in_array(Role::School, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'A school account is required.', 403);
        }
        if ($request->method === 'POST' && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->problem('Form expired', 'Reopen the form and try again.', 403);
        }

        $id = $viewer['id'];
        $school = $this->schools->find($id);
        if ($school === null || SchoolStatus::forSchool($school) === SchoolStatus::Suspended) {
            return $this->problem('School account unavailable', 'Contact Furoeducare about access to this school account.', 403);
        }

        if ($request->path === self::DASHBOARD . '/profile') {
            return $this->saveProfile($school, $viewer, $request);
        }
        if ($request->path === self::DASHBOARD . '/drafts') {
            return $this->saveDraft($school, $viewer, $request);
        }
        if ($request->path === self::DASHBOARD . '/vacancies/publish') {
            return $this->problem('Publication requires management', 'Submit the vacancy for review from School vacancies.', 403);
        }
        if ($request->path !== self::DASHBOARD) {
            // All current routes below this boundary are reserved for approved schools.
            if (!SchoolPolicy::canRecruit($school)) {
                return $this->problem('School approval required', 'Furoeducare must approve your complete school profile before you can use this feature.', 403);
            }
            return $this->problem('Feature unavailable', 'This recruitment feature is not available yet.', 501);
        }

        $tab = $request->query('tab');
        if (!isset(self::TABS[$tab])) {
            $tab = 'overview';
        }
        if ($tab === 'drafts') {
            $rawId = $request->query('id');
            $draft = $rawId === '' ? null : $this->schools->draft($id, $this->positive($rawId) ?? 0);
            if ($rawId !== '' && $draft === null) {
                return $this->problem('Draft unavailable', 'This recruitment draft could not be found.', 404);
            }
            return $this->draftsPage($school, $viewer, $draft, before: $this->positive($request->query('before')) ?? PHP_INT_MAX);
        }
        return $this->schoolPage($tab, $school, $viewer);
    }

    public function review(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if (!in_array(Role::Admin, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'An administrator account is required.', 403);
        }

        $submit = $request->method === 'POST';
        if ($submit && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->problem('Form expired', 'Reopen the review and try again.', 403);
        }
        $rawId = $submit ? $request->form('id') : $request->query('id');
        if (!$submit && $rawId === '') {
            $filter = $request->query('status') === 'all' ? 'all' : 'pending';
            $rows = $this->schools->browse($this->positive($request->query('before')) ?? PHP_INT_MAX,
                $filter === 'all' ? null : SchoolStatus::Pending);
            $next = count($rows) > 30 ? (int) $rows[29]['user_id'] : null;
            return $this->page('review-list', 'School account reviews', [
                'schools' => array_slice($rows, 0, 30), 'next' => $next, 'filter' => $filter,
            ]);
        }

        $id = $this->positive($rawId);
        $school = $id === null ? null : $this->schools->findForReview($id);
        if ($school === null) {
            return $this->problem('School unavailable', 'This school account could not be found.', 404);
        }
        if (!$submit) {
            return $this->reviewPage($school, $viewer);
        }

        [$decision, $reason, $errors] = SchoolInput::decision($request);
        try {
            $checks = ReviewInput::checks($request, ReviewInput::SCHOOL_CHECKS);
        } catch (InvalidArgumentException $exception) {
            $checks = [];
            $errors['decision'] = $exception->getMessage();
        }
        $revision = $this->positive($request->form('revision'));
        if ($revision === null) {
            $errors['decision'] = 'Reopen the review before recording a decision.';
        }
        if ($errors !== []) {
            return $this->reviewPage($school, $viewer, $errors, $decision, $reason, 422, $checks);
        }

        $result = $this->schools->review($viewer['id'], $id, $revision, $decision, $reason, $checks);
        return match ($result) {
            'saved' => $this->noticeAndRedirect('School review recorded.', self::REVIEW . '?id=' . $id),
            'stale' => $this->problem('School profile changed', 'Reopen the school account and review its current details before recording a decision.', 409),
            'incomplete', 'transition', 'checks', 'suspended' => $this->reviewPage($school, $viewer, ['decision' => match ($result) {
                'incomplete' => 'Only a complete profile with a verified email can be approved.',
                'checks' => 'Confirm all verification checks before approving the school.',
                'suspended' => 'Resolve the separate account suspension before approving the school.',
                default => 'This decision is unavailable from the current approval status.',
            }], $decision, $reason, 422, $checks),
            'self' => $this->problem('Access denied', 'Administrators cannot review their own school account.', 403),
            default => $this->problem('School unavailable', 'This school account could not be found.', 404),
        };
    }

    private function saveProfile(array $school, array $viewer, Request $request): Response
    {
        [$values, $errors] = SchoolInput::profile($request, acceptance: !SchoolPolicy::profileComplete($school));
        if ($errors !== []) {
            return $this->schoolPage('profile', $school, $viewer, compact('values', 'errors'), 422);
        }

        try {
            $this->schools->saveProfile($viewer['id'], $values);
        } catch (InstitutionAlreadyRegistered) {
            $errors['school_name'] = 'This institution already has an account. Contact Furoeducare for help.';
            return $this->schoolPage('profile', $school, $viewer, compact('values', 'errors'), 409);
        } catch (DomainException) {
            return $this->problem('Access denied', 'This school account cannot be updated.', 403);
        }
        return $this->noticeAndRedirect('School profile saved.', self::DASHBOARD . '?tab=profile');
    }

    private function saveDraft(array $school, array $viewer, Request $request): Response
    {
        $raw = $request->form('draft_id');
        $id = $this->positive($raw);
        if ($raw !== '' && ($id === null || $this->schools->draft($viewer['id'], $id) === null)) {
            return $this->problem('Draft unavailable', 'This recruitment draft could not be found.', 404);
        }

        [$values, $errors] = SchoolInput::draft($request);
        if ($errors !== []) {
            return $this->draftsPage($school, $viewer, ['id' => $id] + $values, $errors, 422);
        }
        try {
            $saved = $this->schools->saveDraft($viewer['id'], $id, $values);
        } catch (DomainException) {
            return $this->problem('Access denied', 'This school account cannot be updated.', 403);
        }
        if ($saved === null) {
            return $this->problem('Draft unavailable', 'This recruitment draft could not be found.', 404);
        }
        return $this->noticeAndRedirect('Private recruitment draft saved.', self::DASHBOARD . '?tab=drafts&id=' . $saved);
    }

    private function draftsPage(array $school, array $viewer, ?array $draft, array $errors = [], int $status = 200, int $before = PHP_INT_MAX): Response
    {
        $rows = $this->schools->drafts($viewer['id'], $before);
        return $this->schoolPage('drafts', $school, $viewer, [
            'draft' => $draft, 'drafts' => array_slice($rows, 0, 30), 'errors' => $errors,
            'next' => count($rows) > 30 ? (int) $rows[29]['id'] : null,
        ], $status);
    }

    private function schoolPage(string $tab, array $school, array $viewer, array $extra = [], int $status = 200): Response
    {
        return $this->page('dashboard', $tab === 'overview' ? 'School dashboard' : self::TABS[$tab], $extra + [
            'school' => $school, 'email' => $viewer['email'], 'tab' => $tab, 'tabs' => self::TABS,
            'reviews' => in_array($tab, ['overview', 'notifications'], true) ? $this->schools->reviews($viewer['id']) : [],
        ], $status);
    }

    private function reviewPage(array $school, array $viewer, array $errors = [], ?SchoolStatus $decision = null, string $reason = '', int $status = 200, array $checks = []): Response
    {
        return $this->page('review', 'Review school account', compact('school', 'errors', 'decision', 'reason', 'checks') + [
            'history' => $this->schools->reviews((int) $school['user_id'], withReviewer: true),
            'selfReview' => (int) $school['user_id'] === $viewer['id'],
        ], $status);
    }

    private function positive(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function page(string $view, string $title, array $data, int $status = 200): Response
    {
        $data += ['csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(), 'signedIn' => true];
        return Response::html($this->view->render(__DIR__ . '/Views/' . $view . '.php', $title, $data), $status, self::HEADERS);
    }

    private function problem(string $title, string $message, int $status): Response
    {
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
            'message' => $message, 'csrf' => $this->session->csrf(), 'signedIn' => true,
        ]), $status, self::HEADERS);
    }

    private function noticeAndRedirect(string $notice, string $path): Response
    {
        $this->session->notice($notice);
        return $this->redirect($path);
    }

    private function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}

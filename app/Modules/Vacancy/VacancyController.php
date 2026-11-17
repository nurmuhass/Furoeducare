<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Vacancy;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\School\SchoolPolicy;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\School\SchoolStatus;
use Furoeducare\Modules\Teacher\DashboardPage;
use Furoeducare\Modules\Verification\TeacherEligibility;

final class VacancyController
{
    public const PUBLIC = '/teaching-jobs';
    public const SCHOOL = '/dashboard/school/vacancies';
    public const STAFF = '/dashboard/admin/vacancies';
    private const HEADERS = ['Cache-Control' => 'no-store'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly VacancyRepository $vacancies,
        private readonly ApplicationRepository $applications,
        private readonly SchoolRepository $schools,
        private readonly TeacherEligibility $eligibility,
    ) {
    }

    public function browse(Request $request): Response
    {
        $viewer = $this->access->viewer();
        $rows = $this->vacancies->browse(self::id($request->query('before')) ?? PHP_INT_MAX);
        return $this->page('browse', 'Teaching jobs', $viewer, self::paged($rows));
    }

    public function publicDetail(Request $request): Response
    {
        $viewer = $this->access->viewer();
        $id = self::id($request->query('id'));
        $vacancy = $id === null ? null : $this->vacancies->publicVacancy($id);
        if ($vacancy === null) {
            return $this->problem('This vacancy is unavailable.', 404, $viewer);
        }
        return $this->job($vacancy, $viewer);
    }

    private function job(array $vacancy, ?array $viewer, string $statement = '', string $error = ''): Response
    {
        $teacher = $viewer !== null && in_array(Role::Teacher, $viewer['roles'], true);
        $id = (int) $vacancy['id'];
        $alreadyApplied = $teacher && $this->applications->alreadyApplied($viewer['id'], $id);
        return $this->page('job', $vacancy['fields']['title'], $viewer, [
            'vacancy' => $vacancy,
            'teacher' => $teacher,
            'alreadyApplied' => $alreadyApplied,
            'canApply' => $teacher && !$alreadyApplied && $this->eligibility->canApply($viewer['id']),
            'saved' => $teacher && $this->vacancies->isSaved($viewer['id'], $id),
            'statement' => $statement,
            'statementError' => $error,
        ], $error === '' ? 200 : 422);
    }

    public function apply(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if (($problem = $this->guard($viewer, Role::Teacher, $request)) !== null) {
            return $problem;
        }
        $id = self::id($request->form('id'));
        $revision = self::id($request->form('revision'));
        if ($id === null || $revision === null) {
            return $this->problem('Reopen the vacancy before applying.', 422, $viewer);
        }
        $input = $request->form('statement');
        $statement = ApplicationData::statement($input);
        if ($statement === null) {
            $vacancy = $this->vacancies->publicVacancy($id);
            if ($vacancy === null) {
                return $this->problem('This vacancy is unavailable.', 404, $viewer);
            }
            return $this->job($vacancy, $viewer, strlen($input) <= 8000 ? $input : '',
                'Use up to 2,000 characters for your application statement.');
        }
        try {
            $this->applications->submit($id, $viewer['id'], $revision, $statement);
            $this->session->notice('Your application was submitted to Furoeducare.');
            return $this->redirect(DashboardPage::Applications->url());
        } catch (DomainException $error) {
            return $this->problem($error->getMessage(), $error->getCode(), $viewer);
        }
    }

    public function saveJob(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if (($problem = $this->guard($viewer, Role::Teacher, $request)) !== null) {
            return $problem;
        }
        $id = self::id($request->form('id'));
        $action = $request->form('action');
        if ($id === null || !in_array($action, ['save', 'remove'], true)) {
            return $this->problem('Choose a valid job and bookmark action.', 422, $viewer);
        }
        try {
            $this->vacancies->updateSavedJob($viewer['id'], $id, $action === 'save');
            $this->session->notice($action === 'save' ? 'Job saved.' : 'Job removed from Saved Jobs.');
            return $this->redirect(DashboardPage::SavedJobs->url());
        } catch (DomainException $error) {
            return $this->problem($error->getMessage(), $error->getCode(), $viewer);
        }
    }

    public function school(Request $request): Response
    {
        return $this->workspace($request, staff: false);
    }

    public function staff(Request $request): Response
    {
        return $this->workspace($request, staff: true);
    }

    private function workspace(Request $request, bool $staff): Response
    {
        $viewer = $this->access->viewer();
        if (($problem = $this->guard($viewer, $staff ? Role::Admin : Role::School, $request)) !== null) {
            return $problem;
        }
        $actor = $viewer['id'];
        $school = $staff ? null : $this->schools->find($actor);
        if (!$staff && ($school === null || !SchoolPolicy::profileComplete($school)
            || SchoolStatus::forSchool($school) === SchoolStatus::Suspended)) {
            return $this->problem('Complete your school profile before opening the vacancy workspace.', 403, $viewer);
        }
        $root = $staff ? self::STAFF : self::SCHOOL;
        $canSubmit = !$staff && SchoolPolicy::canRecruit($school);
        $posting = $request->method === 'POST';
        $rawId = $posting ? $request->form('id') : $request->query('id');
        $id = self::id($rawId);
        if ($rawId !== '' && $id === null) {
            return $this->problem('This vacancy could not be found.', 404, $viewer);
        }
        $action = $request->form('action');
        $errors = [];
        $values = null;
        if ($posting) {
            try {
                if (($staff || $action !== 'save') && $id === null) {
                    return $this->problem('Choose a vacancy first.', 422, $viewer);
                }
                $revision = self::id($request->form('revision'));
                if ($id !== null && $revision === null) {
                    return $this->problem('Reopen the vacancy before saving.', 422, $viewer);
                }
                if (!$staff && $action === 'save') {
                    [$values, $errors] = VacancyInput::draft($request);
                    if ($errors === []) {
                        if ($id === null) {
                            $id = $this->vacancies->create($actor, $values);
                        } else {
                            $this->vacancies->edit($id, $actor, $revision, $values);
                        }
                    }
                } else {
                    $allowed = $staff ? ['start_review', 'approve', 'reject', 'publish', 'close'] : ['submit', 'close'];
                    $note = VacancyInput::note($request->form('note'));
                    if (!in_array($action, $allowed, true) || $note === null) {
                        return $this->problem('Choose a valid action and use no more than 1,000 characters for a note.', 422, $viewer);
                    }
                    $this->vacancies->transition($id, $actor, $staff, $revision, $action, $note);
                }
                if ($errors === []) {
                    $this->session->notice(match ($action) {
                        'submit' => 'Vacancy submitted for review.',
                        'publish' => 'Vacancy published.',
                        'close' => 'Vacancy closed.',
                        default => $action === 'save' ? 'Vacancy saved.' : 'Review decision saved.',
                    });
                    return $this->redirect($root . '?id=' . $id);
                }
            } catch (DomainException $error) {
                return $this->problem($error->getMessage(), $error->getCode(), $viewer);
            }
        }

        $vacancy = $id === null ? null : $this->vacancies->find($id, $staff ? null : $actor);
        if ($id !== null && ($vacancy === null || ($staff && $vacancy['status'] === VacancyStatus::Draft->value))) {
            return $this->problem('This vacancy could not be found.', 404, $viewer);
        }
        $status = $vacancy === null ? null : VacancyStatus::from($vacancy['status']);
        $new = !$staff && $id === null && ($request->query('new') === '1' || $errors !== []);
        $selected = $staff ? VacancyStatus::tryFrom($request->query('status')) : null;
        $before = self::id($request->query('before')) ?? PHP_INT_MAX;
        $rows = $id === null && !$new ? $this->vacancies->list($before, $staff ? null : $actor, $selected) : [];
        $candidates = $staff && $id !== null ? $this->applications->list($before, null, $id) : [];
        return $this->page('workspace', $staff ? 'Vacancy moderation' : 'School vacancies', $viewer,
            compact('staff', 'root', 'canSubmit', 'vacancy', 'status', 'new', 'selected', 'errors', 'values')
            + ['history' => $id === null ? [] : $this->vacancies->history($id), 'candidates' => self::paged($candidates)]
            + self::paged($rows), $errors === [] ? 200 : 422);
    }

    public function staffApplications(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if (($problem = $this->guard($viewer, Role::Admin, $request)) !== null) {
            return $problem;
        }
        $before = self::id($request->query('before')) ?? PHP_INT_MAX;
        return $this->page('staff-applications', 'Teaching applications', $viewer,
            self::paged($this->applications->list($before, null)));
    }

    public function staffApplication(Request $request): Response
    {
        return $this->application($request, staff: true);
    }

    public function withdraw(Request $request): Response
    {
        return $this->application($request, staff: false);
    }

    private function application(Request $request, bool $staff): Response
    {
        $viewer = $this->access->viewer();
        if (($problem = $this->guard($viewer, $staff ? Role::Admin : Role::Teacher, $request)) !== null) {
            return $problem;
        }
        $posting = $request->method === 'POST';
        $id = self::id($posting ? $request->form('id') : $request->query('id'));
        $application = $id === null ? null : $this->applications->find($id, $staff ? null : $viewer['id']);
        if ($application === null) {
            return $this->problem('This application could not be found.', 404, $viewer);
        }
        if (!$posting) {
            return $this->page('staff-application', 'Review application', $viewer, [
                'application' => $application, 'history' => $this->applications->history($id),
            ]);
        }
        $to = ApplicationStatus::tryFrom($request->form('action'));
        $note = VacancyInput::note($request->form('note'));
        if ($to === null || $note === null || (!$staff && $to !== ApplicationStatus::Withdrawn)) {
            return $this->problem('Choose a valid application action.', 422, $viewer);
        }
        try {
            $this->applications->transition($id, $viewer['id'], $staff, $request->form('from'), $to, $note);
            $this->session->notice($staff ? 'Application status updated.' : 'Application withdrawn.');
            return $this->redirect($staff ? self::STAFF . '/application?id=' . $id : DashboardPage::Applications->url() . '&id=' . $id);
        } catch (DomainException $error) {
            return $this->problem($error->getMessage(), $error->getCode(), $viewer);
        }
    }

    public static function id(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    public static function paged(array $rows): array
    {
        return ['rows' => array_slice($rows, 0, 20), 'next' => count($rows) > 20 ? (int) $rows[19]['id'] : null];
    }

    private function guard(?array $viewer, Role $role, Request $request): ?Response
    {
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if (!in_array($role, $viewer['roles'], true)) {
            return $this->problem('This account cannot access the requested action.', 403, $viewer);
        }
        if ($request->method === 'POST' && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->problem('The form expired. Reopen it and try again.', 403, $viewer);
        }
        return null;
    }

    private function page(string $name, string $title, ?array $viewer, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/' . $name . '.php', $title, $data + [
            'path' => in_array($name, ['browse', 'job'], true) ? self::PUBLIC : '',
            'signedIn' => $viewer !== null, 'csrf' => $viewer === null ? '' : $this->session->csrf(),
            'notice' => $viewer === null ? '' : $this->session->takeNotice(),
        ]), $status, self::HEADERS);
    }

    private function problem(string $message, int $status, ?array $viewer): Response
    {
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php',
            $status === 404 ? 'Page unavailable' : 'Action unavailable', [
                'message' => $message, 'signedIn' => $viewer !== null,
                'csrf' => $viewer === null ? '' : $this->session->csrf(),
            ]), $status, self::HEADERS);
    }

    private function redirect(string $location): Response
    {
        return new Response('', 303, ['Location' => $location] + self::HEADERS);
    }
}

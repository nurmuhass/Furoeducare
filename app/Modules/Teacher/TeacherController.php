<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthInput;
use Furoeducare\Modules\Auth\AuthMailer;
use Furoeducare\Modules\Auth\AuthService;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\EmailToken;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Teacher\Cv\CvDraft;
use Furoeducare\Modules\Teacher\Cv\CvJobs;
use Furoeducare\Modules\Vacancy\ApplicationRepository;
use Furoeducare\Modules\Vacancy\VacancyRepository;
use Furoeducare\Modules\Verification\VerificationEvents;
use Furoeducare\Modules\Verification\VerificationRepository;
use InvalidArgumentException;

final class TeacherController
{
    private const DASHBOARD = '/dashboard/teacher';
    private const SIGNUP = '/register/teacher';
    private const VERIFY = '/verify-teacher';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthService $accounts,
        private readonly AuthAccess $access,
        private readonly RateLimiter $limits,
        private readonly AuthMailer $mailer,
        private readonly TeacherRepository $profiles,
        private readonly TeacherDocuments $documents,
        private readonly CvJobs $cvJobs,
        private readonly VerificationRepository $verification,
        private readonly VerificationEvents $verificationEvents,
        private readonly ApplicationRepository $applications,
        private readonly VacancyRepository $vacancies,
    ) {
    }

    public function register(Request $request): Response
    {
        if (($viewer = $this->access->viewer()) !== null) {
            return $this->redirect($viewer['roles'][0]->dashboard());
        }
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return $this->registration();
        }
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->expiredForm();
        }
        [$values, $errors] = TeacherInput::registration($request, email: true, password: true);
        if ($errors !== []) {
            return $this->registration($values, $errors, status: 422);
        }

        $email = $values['email'];
        if (!$this->limits->allow('register-network', RateLimiter::network($request->remoteAddress), 12, 900)
            || !$this->limits->allow('register-email', $email, 4, 900)) {
            return $this->registration($values, message: 'Too many attempts. Wait before trying again.', status: 429);
        }
        if (!$this->mailer->ready()) {
            return $this->registration($values, message: 'Registration email is temporarily unavailable. Try again later.', status: 503);
        }

        $hash = password_hash($request->form('password'), PASSWORD_DEFAULT);
        $started = hrtime(true);
        $enqueue = fn (string $token) => $this->mailer->deliver(EmailToken::Verification, $email, $token, flow: Role::Teacher);
        $token = $this->accounts->register($email, $enqueue) ?? $this->accounts->requestLink(EmailToken::Verification, $email, $enqueue);
        if ($token !== null) {
            $this->session->rememberRegistration(Role::Teacher, $token, [
                'values' => $values, 'password_hash' => $hash,
                'terms_version' => TeacherInput::TERMS, 'privacy_version' => TeacherInput::PRIVACY,
            ]);
        }
        $remaining = 300_000 - (int) ((hrtime(true) - $started) / 1000);
        if ($remaining > 0) {
            usleep($remaining);
        }
        $this->session->notice('If this address can be registered, check your inbox for a verification link. You can sign in to an existing account.');
        return $this->redirect('/login');
    }

    public function verify(Request $request): Response
    {
        if (($viewer = $this->access->viewer()) !== null) {
            return $this->redirect($viewer['roles'][0]->dashboard());
        }
        $submit = $request->method === 'POST';
        if ($submit && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->expiredForm();
        }
        $token = $submit ? $request->form('token') : $request->query('token');
        if (!AuthInput::validToken($token)) {
            return $this->invalidLink();
        }
        if (!$submit && !$this->accounts->canConfirmProfileEmail($token)) {
            return $this->invalidLink();
        }
        $draft = $this->session->pendingRegistration(Role::Teacher, $token);
        if ($draft !== null && ($draft['terms_version'] !== TeacherInput::TERMS || $draft['privacy_version'] !== TeacherInput::PRIVACY)) {
            $draft = null;
        }
        if (!$submit) {
            return $this->registration($draft['values'] ?? [], mode: 'verify', token: $token, confirm: $draft !== null);
        }

        if ($draft !== null && $request->form('confirm_registration') === '1') {
            $values = $draft['values'];
            $hash = static fn (): string => $draft['password_hash'];
        } else {
            [$values, $errors] = TeacherInput::registration($request, email: true, password: true);
            if ($errors !== []) {
                return $this->registration($values, $errors, mode: 'verify', token: $token, status: 422);
            }
            $password = $request->form('password');
            $hash = static fn (): string => password_hash($password, PASSWORD_DEFAULT);
        }
        $account = $this->accounts->confirmProfileEmail(
            Role::Teacher, $token, $values['email'], $hash, fn (int $id) => $this->profiles->create($id, $values)
        );
        if ($account === null) {
            return $this->invalidLink();
        }

        $this->session->authenticate($account['id'], $account['version']);
        $this->session->notice('Your email is verified. You can now build your professional profile.');
        return $this->redirect(self::DASHBOARD);
    }

    public function onboarding(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if (!in_array(Role::Teacher, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'Your account cannot open the teacher dashboard.', 403, true);
        }
        if ($request->method === 'POST' && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->expiredForm(true);
        }

        $profile = $this->profiles->profile($viewer['id']);
        if ($profile === null) {
            return $this->setup($request, $viewer);
        }
        try {
            return match ($request->path) {
                self::DASHBOARD => $this->showDash($request, $profile, $viewer),
                self::DASHBOARD . '/setup' => $this->redirect(self::DASHBOARD),
                self::DASHBOARD . '/profile' => $this->save($request, $profile, $viewer),
                self::DASHBOARD . '/cv' => $this->cvReview($request, $profile, $viewer),
                self::DASHBOARD . '/documents/upload' => $this->upload($request, $profile, $viewer),
                self::DASHBOARD . '/documents/download' => $this->download($request, $viewer['id']),
                self::DASHBOARD . '/documents/remove' => $this->remove($request, $viewer['id']),
                self::DASHBOARD . '/verification/submit' => $this->submitVerification($viewer['id']),
            };
        } catch (DomainException) {
            return $this->problem('Access denied', 'An active teacher account is required.', 403, true);
        }
    }

    private function submitVerification(int $id): Response
    {
        $result = $this->verification->submitTeacher($id);
        if ($result === 'saved') {
            $this->session->notice('Your professional profile was submitted for verification.');
            return $this->redirect(self::DASHBOARD);
        }
        if (in_array($result, ['forbidden', 'missing'], true)) {
            return $this->problem('Verification unavailable', 'An active teacher account is required.', 403, true);
        }
        return $this->problem('Profile not submitted', match ($result) {
            'incomplete' => 'Complete all required sections before submitting your profile for verification.',
            'unchanged' => 'Update your profile or documents before resubmitting.',
            default => 'Your current verification status does not allow a new submission.',
        }, 422, true);
    }

    private function setup(Request $request, array $viewer): Response
    {
        if ($request->path === self::DASHBOARD && $request->method !== 'POST') {
            return $this->registration(mode: 'setup', signedIn: true);
        }
        if ($request->path !== self::DASHBOARD . '/setup') {
            return $this->redirect(self::DASHBOARD);
        }
        [$values, $errors] = TeacherInput::registration($request, email: false, password: false);
        if ($errors !== []) {
            return $this->registration($values, $errors, mode: 'setup', status: 422, signedIn: true);
        }

        $this->profiles->create($viewer['id'], $values);
        $this->session->notice('Your private professional profile is ready to edit.');
        return $this->redirect(self::DASHBOARD);
    }

    private function save(Request $request, array $profile, array $viewer): Response
    {
        $section = Section::tryFrom($request->form('section'));
        if ($section === null || $section === Section::Documents) {
            return $this->problem('Invalid profile section', 'Choose a profile section and try again.', 422, true);
        }
        [$values, $errors] = TeacherInput::section($section, $request, $profile['category']);
        if ($errors !== []) {
            return $this->dashboard($profile, $viewer, $section, $values, $errors, 422);
        }

        $this->profiles->save($viewer['id'], $section, $values);
        $this->session->notice('Section saved.');
        return $this->sectionRedirect($section);
    }

    private function cvReview(Request $request, array $profile, array $viewer): Response
    {
        $teacher = $viewer['id'];
        $job = $this->cvJobs->current($teacher);
        $document = $this->documentId($request->form('document_id'));
        if ($request->method === 'POST' && $request->form('action') === 'queue'
            && $job !== null && $document === (int) $job['document_id'] && $job['status'] === 'new') {
            $this->profiles->queueExistingCv($teacher, $document);
            return $this->sectionRedirect(Section::Documents);
        }

        if ($job === null || $job['status'] !== 'ready') {
            return $this->sectionRedirect(Section::Documents);
        }

        $draft = CvDraft::validate(json_decode($job['draft'], true, flags: JSON_THROW_ON_ERROR));
        $values = $draft;
        $errors = [];
        if ($request->method === 'POST') {
            if ($document !== (int) $job['document_id']) {
                return $this->staleCvDraft();
            }
            [$values, $errors] = TeacherInput::cvReview($request);
            if ($errors === []) {
                if (!$this->profiles->applyCvDraft($teacher, $document, $values)) {
                    return $this->staleCvDraft();
                }
                $this->session->notice('CV review saved. Check your profile sections for the added details.');
                return $this->sectionRedirect(Section::Documents);
            }
        }

        return $this->workspace(DashboardPage::Documents, 'cv-review', $profile, $viewer,
            compact('job', 'draft', 'values', 'errors'), $errors === [] ? 200 : 422, 'Review CV suggestions');
    }

    private function staleCvDraft(): Response
    {
        $this->session->notice('That extraction draft is no longer available. Check your current CV for updates.');
        return $this->sectionRedirect(Section::Documents);
    }

    private function upload(Request $request, array $profile, array $viewer): Response
    {
        try {
            $this->documents->upload($viewer['id'], $request->form('kind'), $request->uploadedFile('document'));
        } catch (InvalidArgumentException $error) {
            return $this->dashboard($profile, $viewer, Section::Documents, ['kind' => $request->form('kind')],
                ['document' => $error->getMessage()], 422);
        }
        $this->session->notice($request->form('kind') === 'cv' ? 'CV uploaded and queued for extraction.' : 'Document saved.');
        return $this->sectionRedirect(Section::Documents);
    }

    private function download(Request $request, int $id): Response
    {
        $document = $this->documentId($request->query('id'));
        return ($document !== null ? $this->documents->download($id, $document) : null) ?? $this->documentNotFound();
    }

    private function remove(Request $request, int $id): Response
    {
        $document = $this->documentId($request->form('id'));
        if ($document === null || !$this->documents->remove($id, $document)) {
            return $this->documentNotFound();
        }
        $this->session->notice('Document removed.');
        return $this->sectionRedirect(Section::Documents);
    }

    private function documentId(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function documentNotFound(): Response
    {
        return $this->problem('Document unavailable', 'This document could not be found.', 404, true);
    }

    private function showDash(Request $request, array $profile, array $viewer): Response
    {
        $section = Section::tryFrom($request->query('section'));
        $tab = DashboardPage::tryFrom($request->query('tab'))
            ?? ($section === null ? DashboardPage::Overview : DashboardPage::forSection($section));

        return match ($tab) {
            DashboardPage::Profile => $this->dashboard(
                $profile, $viewer, $section !== null && DashboardPage::forSection($section) === $tab ? $section : Section::Personal
            ),
            DashboardPage::Documents => $this->dashboard($profile, $viewer, Section::Documents),
            DashboardPage::Availability => $this->dashboard($profile, $viewer, Section::Availability),
            DashboardPage::Overview => $this->workspace($tab, 'overview', $profile, $viewer, [
                'applicationCounts' => $this->applications->counts($viewer['id']),
                'placementEvents' => $this->verificationEvents->forUser($viewer['id'], 3, 'placement.'),
            ]),
            DashboardPage::Jobs => $this->workspace($tab, 'jobs', $profile, $viewer),
            DashboardPage::Messages => $this->redirect('/messages'),
            DashboardPage::Library => $this->redirect('/digital-library'),
            DashboardPage::Applications => $this->myApplications($request, $profile, $viewer),
            DashboardPage::Placements => $this->workspace($tab, 'placements', $profile, $viewer, [
                'placementEvents' => $this->verificationEvents->forUser($viewer['id'], 5, 'placement.'),
            ]),
            DashboardPage::SavedJobs => $this->savedJobs($request, $profile, $viewer),
            DashboardPage::Notifications => $this->redirect('/notifications'),
            DashboardPage::Settings => $this->workspace($tab, 'settings', $profile, $viewer),
            default => $this->workspace($tab, 'pending', $profile, $viewer),
        };
    }

    private function myApplications(Request $request, array $profile, array $viewer): Response
    {
        $rawId = $request->query('id');
        $id = $this->documentId($rawId);
        $application = $id === null ? null : $this->applications->find($id, $viewer['id']);
        if ($rawId !== '' && $application === null) {
            return $this->problem('Application unavailable', 'This application could not be found.', 404, true);
        }
        $rows = $id === null
            ? $this->applications->list($this->documentId($request->query('before')) ?? PHP_INT_MAX, $viewer['id'])
            : [];
        return $this->workspace(DashboardPage::Applications, 'applications', $profile, $viewer, [
            'application' => $application, 'history' => $id === null ? [] : $this->applications->history($id),
            'applications' => array_slice($rows, 0, 20), 'next' => count($rows) > 20 ? (int) $rows[19]['id'] : null,
        ]);
    }

    private function savedJobs(Request $request, array $profile, array $viewer): Response
    {
        $before = $this->documentId($request->query('before')) ?? PHP_INT_MAX;
        $rows = $this->vacancies->savedJobs($viewer['id'], $before);
        return $this->workspace(DashboardPage::SavedJobs, 'saved-jobs', $profile, $viewer, [
            'jobs' => array_slice($rows, 0, 20), 'next' => count($rows) > 20 ? (int) $rows[19]['vacancy_id'] : null,
        ]);
    }

    private function dashboard(array $profile, array $viewer, Section $section, ?array $values = null, array $errors = [], int $status = 200): Response
    {
        $stored = $profile['sections'][$section->value] ?? [];
        if ($section === Section::Personal) {
            $stored += array_intersect_key($profile, array_flip(['first_name', 'last_name', 'phone']));
        }

        return $this->workspace(DashboardPage::forSection($section), 'dashboard', $profile, $viewer, [
            'section' => $section,
            'values' => ($values ?? []) + $stored,
            'errors' => $errors,
            'cv' => $section === Section::Documents ? $this->cvJobs->current($viewer['id']) : null,
        ], $status);
    }

    private function workspace(
        DashboardPage $tab, string $panel, array $profile, array $viewer, array $data = [],
        int $status = 200, ?string $title = null,
    ): Response {
        return $this->page('workspace', $title ?? $tab->label(), [
            'teacherWorkspace' => true,
            'signedIn' => true,
            'panel' => $panel,
            'tab' => $tab,
            'profile' => $profile,
            'email' => $viewer['email'],
            'roleLinks' => $viewer['roles'],
            'missing' => ProfileCompletion::missing($profile),
            'notice' => $this->session->takeNotice(),
            'recentVerificationEvents' => $tab === DashboardPage::Overview ? $this->verificationEvents->forUser($viewer['id'], 3) : [],
        ] + $data, $status);
    }

    private function registration(
        array $values = [],
        array $errors = [],
        string $mode = 'signup',
        string $token = '',
        string $message = '',
        bool $confirm = false,
        int $status = 200,
        bool $signedIn = false,
    ): Response {
        $titles = ['signup' => 'Teacher and NYSC registration', 'verify' => 'Verify your teacher account', 'setup' => 'Set up your teacher profile'];
        $headers = $status === 429 ? ['Retry-After' => '900'] : [];
        return $this->page('registration', $titles[$mode], compact(
            'values', 'errors', 'mode', 'token', 'message', 'confirm', 'signedIn'
        ), $status, $headers);
    }

    private function invalidLink(): Response
    {
        return $this->registration(mode: 'verify', message: 'This verification link is invalid or expired. Request a new link.', status: 422);
    }

    private function expiredForm(bool $signedIn = false): Response
    {
        return $this->problem('Form expired', 'Reopen the page and submit the form again.', 403, $signedIn);
    }

    private function problem(string $title, string $message, int $status, bool $signedIn): Response
    {
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
            'message' => $message, 'signedIn' => $signedIn, 'csrf' => $this->session->csrf(),
        ]), $status, self::HEADERS);
    }

    private function page(string $name, string $title, array $data, int $status = 200, array $headers = []): Response
    {
        $data['csrf'] = $this->session->csrf();
        return Response::html($this->view->render(__DIR__ . '/Views/' . $name . '.php', $title, $data), $status, self::HEADERS + $headers);
    }

    private function sectionRedirect(Section $section): Response
    {
        return $this->redirect(self::DASHBOARD . '?section=' . $section->value);
    }

    private function redirect(string $url): Response
    {
        return new Response('', 303, ['Location' => $url] + self::HEADERS);
    }
}

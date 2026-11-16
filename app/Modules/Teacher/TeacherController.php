<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

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
        $token = $this->accounts->register($email) ?? $this->accounts->requestLink(EmailToken::Verification, $email);
        if ($token !== null) {
            $this->session->rememberTeacherRegistration($token, [
                'values' => $values, 'password_hash' => $hash,
                'terms_version' => TeacherInput::TERMS, 'privacy_version' => TeacherInput::PRIVACY,
            ]);
            $this->mailer->deliver(EmailToken::Verification, $email, $token, teacher: true);
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
        if (!$submit && !$this->accounts->canConfirmTeacherEmail($token)) {
            return $this->invalidLink();
        }
        $draft = $this->session->pendingTeacherRegistration($token);
        if ($draft !== null && ($draft['terms_version'] !== TeacherInput::TERMS || $draft['privacy_version'] !== TeacherInput::PRIVACY)) {
            $draft = null;
        }
        if (!$submit) {
            return $this->registration($draft['values'] ?? [], mode: 'verify', token: $token, confirm: $draft !== null);
        }

        if ($draft !== null && $request->form('confirm_registration') === '1') {
            $values = $draft['values'];
            $hash = $draft['password_hash'];
        } else {
            [$values, $errors] = TeacherInput::registration($request, email: true, password: true);
            if ($errors !== []) {
                return $this->registration($values, $errors, mode: 'verify', token: $token, status: 422);
            }
            $hash = password_hash($request->form('password'), PASSWORD_DEFAULT);
        }
        $account = $this->accounts->confirmTeacherEmail(
            $token, $values['email'], $hash, fn (int $id) => $this->profiles->create($id, $values)
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
        return match ($request->path) {
            self::DASHBOARD => $this->dashboard($profile, $viewer, Section::tryFrom($request->query('section')) ?? Section::Personal),
            self::DASHBOARD . '/setup' => $this->redirect(self::DASHBOARD),
            self::DASHBOARD . '/profile' => $this->save($request, $profile, $viewer),
            self::DASHBOARD . '/documents/upload' => $this->upload($request, $profile, $viewer),
            self::DASHBOARD . '/documents/download' => $this->download($request, $viewer['id']),
            self::DASHBOARD . '/documents/remove' => $this->remove($request, $viewer['id']),
        };
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

    private function upload(Request $request, array $profile, array $viewer): Response
    {
        try {
            $this->documents->upload($viewer['id'], $request->form('kind'), $request->uploadedFile('document'));
        } catch (InvalidArgumentException $error) {
            return $this->dashboard($profile, $viewer, Section::Documents, ['kind' => $request->form('kind')],
                ['document' => $error->getMessage()], 422);
        }
        $this->session->notice('Document saved.');
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

    private function dashboard(array $profile, array $viewer, Section $section, ?array $values = null, array $errors = [], int $status = 200): Response
    {
        $stored = $profile['sections'][$section->value] ?? [];
        if ($section === Section::Personal) {
            $stored += array_intersect_key($profile, array_flip(['first_name', 'last_name', 'phone']));
        }

        return $this->page('dashboard', 'Teacher onboarding', [
            'profile' => $profile, 'email' => $viewer['email'], 'section' => $section,
            'values' => ($values ?? []) + $stored, 'missing' => ProfileCompletion::missing($profile),
            'errors' => $errors, 'notice' => $this->session->takeNotice(), 'signedIn' => true,
            'roleLinks' => $viewer['roles'],
        ], $status);
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

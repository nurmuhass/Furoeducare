<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;

final class AuthController
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
    private const LOGIN_WINDOW = 900;

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthService $accounts,
        private readonly RateLimiter $limits,
        private readonly AuthMailer $mailer,
        private readonly AuthAccess $access,
    ) {
    }

    public function show(Request $request): Response
    {
        if (in_array($request->path, ['/login', '/register'], true) && ($viewer = $this->access->viewer()) !== null) {
            return $this->redirect($viewer['roles'][0]->dashboard());
        }

        return $this->form(
            $request->path,
            token: $request->query('token'),
            notice: $this->session->takeNotice(),
            values: $request->path === '/resend-verification' && $request->query('flow') === 'teacher' ? ['flow' => 'teacher'] : []
        );
    }

    public function submit(Request $request): Response
    {
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->problem('Form expired', 'Reopen the page and submit the form again.', 403);
        }

        return match ($request->path) {
            '/login' => $this->login($request),
            '/register' => $this->register($request),
            '/resend-verification' => $this->requestLink($request, EmailToken::Verification),
            '/forgot-password' => $this->requestLink($request, EmailToken::PasswordReset),
            '/verify-email' => $this->confirmEmail($request),
            '/reset-password' => $this->resetPassword($request),
            '/logout' => $this->logout(),
        };
    }

    public function dashboard(?Role $role): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if ($role === null) {
            return $this->redirect($viewer['roles'][0]->dashboard());
        }
        if (!in_array($role, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'Your account cannot open this dashboard.', 403, true);
        }

        $school = $role === Role::School ? $this->accounts->school($viewer['id']) : null;
        return $this->page('dashboard', ucfirst($role->value) . ' dashboard', [
            'path' => $role->dashboard(),
            'role' => $role,
            'roleLinks' => $viewer['roles'],
            'email' => $viewer['email'],
            'school' => $school,
            'signedIn' => true,
            'csrf' => $this->session->csrf(),
        ]);
    }

    private function login(Request $request): Response
    {
        $email = AuthInput::email($request->form('email'));
        $password = $request->form('password');
        $network = RateLimiter::network($request->remoteAddress);
        $identity = $network . "\0" . ($email ?? 'invalid-email');
        $values = ['email' => $email ?? ''];

        if (!$this->limits->allow('login-network', $network, 30, self::LOGIN_WINDOW)
            || !$this->limits->allow('login-identity', $identity, 5, self::LOGIN_WINDOW)
            || !$this->limits->allow('login-account', $email ?? 'invalid-email', 20, self::LOGIN_WINDOW)) {
            return $this->limited('/login', $values, self::LOGIN_WINDOW);
        }
        $user = $email !== null && AuthInput::canVerifyPassword($password)
            ? $this->accounts->checkPassword($email, $password) : null;

        if ($user === null) {
            return $this->form('/login', $values, message: 'Email address or password is incorrect.', status: 401);
        }
        if ($user['suspended_at'] !== null) {
            return $this->form('/login', $values, message: 'This account is suspended. Contact Furoeducare for help.', status: 403);
        }
        if ($user['email_verified_at'] === null) {
            return $this->form('/login', $values, message: 'Verify your email before signing in. You can request a new verification link.', status: 403);
        }

        $id = (int) $user['id'];
        $roles = $this->accounts->roles($id);
        if ($roles === []) {
            return $this->form('/login', $values, message: 'This account has no dashboard access. Contact Furoeducare for help.', status: 403);
        }

        $this->accounts->rehashIfNeeded($user, $password);
        $this->limits->clear('login-identity', $identity);
        $this->limits->clear('login-account', $email);
        $this->session->authenticate($id, (int) $user['auth_version']);
        return $this->redirect($roles[0]->dashboard());
    }

    private function register(Request $request): Response
    {
        $email = AuthInput::email($request->form('email'));
        if ($email === null) {
            return $this->form('/register', errors: ['email' => 'Enter a valid email address.'], status: 422);
        }
        $values = ['email' => $email];

        $network = RateLimiter::network($request->remoteAddress);
        if (!$this->limits->allow('register-network', $network, 12, 900)
            || !$this->limits->allow('register-email', $email, 4, 900)) {
            return $this->limited('/register', $values, 900);
        }
        if (!$this->mailer->ready()) {
            return $this->form('/register', $values, message: 'Registration email is temporarily unavailable. Try again later.', status: 503);
        }

        $started = hrtime(true);
        $token = $this->accounts->register($email);
        if ($token !== null) {
            $this->mailer->deliver(EmailToken::Verification, $email, $token);
        }
        $this->padEmailResponse($started);
        $this->session->notice('If this address can be registered, check your inbox for a verification link. You can also request a new link or sign in to an existing account.');
        return $this->redirect('/login');
    }

    private function requestLink(Request $request, EmailToken $purpose): Response
    {
        $email = AuthInput::email($request->form('email'));
        $teacherFlow = $purpose === EmailToken::Verification && $request->form('flow') === 'teacher';
        $values = ['email' => $email ?? '', 'flow' => $teacherFlow ? 'teacher' : ''];
        if ($email === null) {
            return $this->form($request->path, $values, ['email' => 'Enter a valid email address.'], status: 422);
        }

        $network = RateLimiter::network($request->remoteAddress);
        if (!$this->limits->allow($purpose->value . '-network', $network, 20, 900)
            || !$this->limits->allow($purpose->value . '-email', $email, 3, 3600)) {
            return $this->limited($request->path, $values, 3600);
        }
        if (!$this->mailer->ready()) {
            return $this->form($request->path, $values, message: 'Account email is temporarily unavailable. Try again later.', status: 503);
        }

        $started = hrtime(true);
        $token = $this->accounts->requestLink($purpose, $email);
        if ($token !== null) {
            $rebound = $purpose === EmailToken::Verification && $this->session->renewTeacherRegistration($email, $token);
            $this->mailer->deliver($purpose, $email, $token, teacher: $teacherFlow || $rebound);
        }
        $this->padEmailResponse($started);
        $this->session->notice('If this address is eligible, an email with a link is on its way.');
        return $this->redirect($request->path . ($teacherFlow ? '?flow=teacher' : ''));
    }

    private function confirmEmail(Request $request): Response
    {
        $token = $request->form('token');
        $invalidLink = 'This verification link is invalid or expired. Request a new link.';
        if (!AuthInput::validToken($token)) {
            return $this->form('/verify-email', message: $invalidLink, status: 422);
        }

        $password = $request->form('password');
        $role = Role::tryFrom($request->form('role'));
        if ($role === Role::Teacher) {
            return $this->redirect('/verify-teacher?token=' . $token);
        }
        $school = trim($request->form('school_name'));
        $errors = AuthInput::passwordErrors($password, $request->form('password_confirmation'));
        if (!in_array($role, Role::publicRoles(), true)) {
            $errors['role'] = 'Choose a teacher, school or parent account.';
        }
        if ($role === Role::School && !AuthInput::validSchoolName($school)) {
            $errors['school_name'] = 'Enter a school name using up to 160 characters.';
        }
        if ($errors !== []) {
            return $this->form('/verify-email', [
                'role' => $role?->value ?? '',
                'school_name' => substr($school, 0, 640),
            ], $errors, token: $token, status: 422);
        }
        if (!$this->accounts->confirmEmail($token, $password, $role, $school)) {
            return $this->form('/verify-email', message: $invalidLink, status: 422);
        }

        $this->session->notice('Your email is verified. You can now sign in.');
        return $this->redirect('/login');
    }

    private function resetPassword(Request $request): Response
    {
        $token = $request->form('token');
        if (!AuthInput::validToken($token)) {
            return $this->form('/reset-password', message: 'This reset link is invalid or expired. Request a new link.', status: 422);
        }
        $password = $request->form('password');
        $errors = AuthInput::passwordErrors($password, $request->form('password_confirmation'));
        if ($errors !== []) {
            return $this->form('/reset-password', errors: $errors, token: $token, status: 422);
        }
        if (!$this->accounts->resetPassword($token, $password)) {
            return $this->form('/reset-password', message: 'This reset link is invalid or expired. Request a new link.', status: 422);
        }

        $this->session->logout();
        $this->session->notice('Your password has been reset. Sign in with your new password.');
        return $this->redirect('/login');
    }

    private function logout(): Response
    {
        $this->session->logout();
        return $this->redirect('/login');
    }

    private function limited(string $path, array $values, int $window): Response
    {
        return $this->form(
            $path, $values, message: 'Too many attempts. Wait before trying again.',
            status: 429, headers: ['Retry-After' => (string) $window]
        );
    }

    private function form(
        string $path,
        array $values = [],
        array $errors = [],
        string $message = '',
        string $token = '',
        string $notice = '',
        int $status = 200,
        array $headers = [],
    ): Response {
        $title = match ($path) {
            '/login' => 'Log in',
            '/register' => 'Create an account',
            '/forgot-password' => 'Forgot your password?',
            '/resend-verification' => 'Resend verification email',
            '/verify-email' => 'Verify your email',
            '/reset-password' => 'Set a new password',
        };
        return $this->page('form', $title, [
            'path' => $path, 'values' => $values, 'errors' => $errors, 'message' => $message,
            'token' => AuthInput::validToken($token) ? $token : '', 'notice' => $notice,
            'csrf' => $this->session->csrf(),
        ], $status, $headers);
    }

    private function problem(string $title, string $message, int $status, bool $signedIn = false): Response
    {
        return $this->page('problem', $title, [
            'message' => $message, 'signedIn' => $signedIn, 'csrf' => $this->session->csrf(),
        ], $status);
    }

    private function page(string $name, string $title, array $data, int $status = 200, array $headers = []): Response
    {
        return Response::html(
            $this->view->render(__DIR__ . '/Views/' . $name . '.php', $title, $data),
            $status,
            self::HEADERS + $headers
        );
    }

    private function redirect(string $destination): Response
    {
        return new Response('', 303, ['Location' => $destination] + self::HEADERS);
    }

    private function padEmailResponse(int $started): void
    {
        $remaining = 300_000 - (int) ((hrtime(true) - $started) / 1000);
        if ($remaining > 0) {
            usleep($remaining);
        }
    }
}

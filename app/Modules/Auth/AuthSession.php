<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

use RuntimeException;

final class AuthSession
{
    private const NAME = 'FUROEDUCARE_SESSION';
    private const IDLE_LIMIT = 1_800;
    private const ABSOLUTE_LIMIT = 28_800;

    public function __construct(private readonly string $directory, private readonly bool $secure)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the private session directory.');
        }
        session_name(self::NAME);
        session_set_cookie_params($this->cookieOptions() + ['lifetime' => 0]);
        if (!session_start([
            'save_handler' => 'files',
            'save_path' => $this->directory,
            'use_strict_mode' => 1,
            'use_cookies' => 1,
            'use_only_cookies' => 1,
            'use_trans_sid' => 0,
            'gc_maxlifetime' => self::ABSOLUTE_LIMIT,
            'cache_limiter' => '',
        ])) {
            throw new RuntimeException('Unable to start the authentication session.');
        }
    }

    public function csrf(): string
    {
        $this->start();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function acceptsCsrf(string $token): bool
    {
        $this->start();
        $expected = $_SESSION['csrf'] ?? null;
        return is_string($expected) && hash_equals($expected, $token);
    }

    public function authenticate(int $userId, int $version): void
    {
        $this->start();
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the authentication session.');
        }
        $_SESSION['account'] = [
            'id' => $userId,
            'version' => $version,
            'issued' => time(),
            'seen' => time(),
        ];
    }

    public function rememberTeacherRequest(string $reference): void
    {
        if (AuthInput::teacherRequestReference($reference) === null) {
            return;
        }

        $this->start();
        $_SESSION['teacher_request'] = ['reference' => $reference, 'expires' => time() + self::IDLE_LIMIT];
    }

    public function teacherRequestReference(): ?string
    {
        $this->start();
        $request = $_SESSION['teacher_request'] ?? [];
        if (($request['expires'] ?? 0) <= time()) {
            unset($_SESSION['teacher_request']);
            return null;
        }

        return AuthInput::teacherRequestReference($request['reference'] ?? null);
    }

    public function teacherRequestPath(?string $reference = null): ?string
    {
        $reference = AuthInput::teacherRequestReference($reference) ?? $this->teacherRequestReference();
        return $reference === null ? null : '/find-teachers/request?ref=' . $reference;
    }

    public function rememberRegistration(Role $role, #[\SensitiveParameter] string $token, #[\SensitiveParameter] array $draft): void
    {
        $this->start();
        $_SESSION[$role->value . '_registration'] = ['token_hash' => hash('sha256', $token), 'draft' => $draft];
    }

    public function pendingRegistration(Role $role, #[\SensitiveParameter] string $token): ?array
    {
        $this->start();
        $pending = $_SESSION[$role->value . '_registration'] ?? null;
        return $pending !== null && hash_equals($pending['token_hash'], hash('sha256', $token))
            ? $pending['draft'] : null;
    }

    public function renewRegistration(Role $role, string $email, #[\SensitiveParameter] string $token): bool
    {
        $this->start();
        $draft = $_SESSION[$role->value . '_registration']['draft'] ?? null;
        if ($draft === null || ($draft['values']['email'] ?? '') !== $email) {
            return false;
        }
        $this->rememberRegistration($role, $token, $draft);
        return true;
    }

    public function account(): ?array
    {
        $this->start();
        $account = $_SESSION['account'] ?? null;
        if (!is_array($account)) {
            return null;
        }

        $now = time();
        if ($now - $account['issued'] >= self::ABSOLUTE_LIMIT || $now - $account['seen'] >= self::IDLE_LIMIT) {
            $this->logout();
            return null;
        }
        $_SESSION['account']['seen'] = $now;
        return $account;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy the authentication session.');
        }
        unset($_COOKIE[self::NAME]);
        session_id('');
        setcookie(self::NAME, '', $this->cookieOptions() + ['expires' => time() - 3600]);
    }

    public function notice(string $message): void
    {
        $this->start();
        $_SESSION['notice'] = $message;
    }

    public function takeNotice(): string
    {
        $this->start();
        $message = $_SESSION['notice'] ?? '';
        unset($_SESSION['notice']);
        return $message;
    }

    private function cookieOptions(): array
    {
        return ['path' => '/', 'domain' => '', 'secure' => $this->secure, 'httponly' => true, 'samesite' => 'Lax'];
    }
}

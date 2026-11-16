<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

final class AuthAccess
{
    public function __construct(private readonly AuthSession $session, private readonly AuthService $accounts)
    {
    }

    public function viewer(): ?array
    {
        $session = $this->session->account();
        if ($session === null) {
            return null;
        }

        $user = $this->accounts->findUser($session['id']);
        if ($user === null || $user['suspended_at'] !== null || $user['email_verified_at'] === null
            || (int) $user['auth_version'] !== $session['version']) {
            $this->session->logout();
            return null;
        }

        $roles = $this->accounts->roles($session['id']);
        if ($roles === []) {
            $this->session->logout();
            return null;
        }
        return ['id' => $session['id'], 'email' => $user['email'], 'roles' => $roles];
    }
}

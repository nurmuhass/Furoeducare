<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

enum EmailToken: string
{
    case Verification = 'email_verification_tokens';
    case PasswordReset = 'password_reset_tokens';

    public function path(): string
    {
        return $this === self::Verification ? '/verify-email' : '/reset-password';
    }

    public function lifetime(): int
    {
        return $this === self::Verification ? 86_400 : 1_800;
    }
}

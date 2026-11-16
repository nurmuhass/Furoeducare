<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

final class AuthInput
{
    public static function email(string $input): ?string
    {
        $email = strtolower(trim($input));
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public static function passwordErrors(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): array
    {
        $errors = [];
        if (!self::canVerifyPassword($password) || preg_match('/\A.{12,72}\z/us', $password) !== 1) {
            $errors['password'] = 'Use at least 12 characters and at most 72 bytes.';
        }
        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'Enter the same password again.';
        }
        return $errors;
    }

    public static function canVerifyPassword(#[\SensitiveParameter] string $password): bool
    {
        return $password !== '' && strlen($password) <= 72 && !str_contains($password, "\0");
    }

    public static function validSchoolName(string $name): bool
    {
        return preg_match('/\A[^\p{C}]{1,160}\z/u', $name) === 1 && preg_match('/\S/u', $name) === 1;
    }

    public static function validToken(#[\SensitiveParameter] string $token): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
    }
}

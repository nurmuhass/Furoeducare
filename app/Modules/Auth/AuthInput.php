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

    public static function phone(string $input): ?string
    {
        $phone = preg_replace('/[()\s-]/', '', $input);
        if (preg_match('/\A0[0-9]{10}\z/', $phone) === 1) {
            $phone = '+234' . substr($phone, 1);
        } elseif (preg_match('/\A234[0-9]{10}\z/', $phone) === 1) {
            $phone = '+' . $phone;
        }
        return preg_match('/\A\+[1-9][0-9]{6,14}\z/', $phone) === 1 ? $phone : null;
    }

    public static function validToken(#[\SensitiveParameter] string $token): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
    }

    public static function teacherRequestReference(mixed $input): ?string
    {
        return is_string($input) && preg_match('/\AFT-[A-F0-9]{20}\z/', $input) === 1 ? $input : null;
    }
}

<?php
declare(strict_types=1);
namespace Furoeducare\Modules\Settings;

use Furoeducare\Modules\Auth\AuthInput;

final class SettingsInput
{
    public static function email(string $value): string
    {
        return AuthInput::email($value) ?? throw new SettingsValidation(['new_email' => 'Enter a valid email address.']);
    }

    public static function phone(string $value): string
    {
        return AuthInput::phone($value) ?? throw new SettingsValidation(['phone' => 'Enter a valid Nigerian or international phone number.']);
    }

    public static function password(#[\SensitiveParameter] string $value, #[\SensitiveParameter] string $confirmation): string
    {
        $errors = AuthInput::passwordErrors($value, $confirmation);
        if ($errors !== []) throw new SettingsValidation($errors);
        return $value;
    }

    public static function name(string $value): string { return self::text($value, 'display_name', 160); }
    public static function reason(string $value): string { return self::text($value, 'deactivation_reason', 1000); }

    /** Appearance may return only to a plain local page, never a security link. */
    public static function returnPath(string $value): string
    {
        if (strlen($value) > 1500 || preg_match('~\A/(?:[a-z][a-z0-9-]*(?:/[a-z0-9-]+)*)?(?:\?[A-Za-z0-9_%=&.+-]*)?\z~D', $value) !== 1) return '/settings/account';
        $query = [];
        parse_str((string) (parse_url($value, PHP_URL_QUERY) ?? ''), $query);
        if (str_starts_with($value, '/settings/email-change') || in_array('token', array_map(static fn (int|string $key): string => strtolower((string) $key), array_keys($query)), true)) return '/settings/account';
        return $value;
    }

    private static function text(string $value, string $field, int $max): string
    {
        if (trim($value) === '' || preg_match('/\A.{1,' . $max . '}\z/us', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new SettingsValidation([$field => 'Enter a value up to ' . $max . ' characters, without control characters.']);
        }
        return trim($value);
    }
}

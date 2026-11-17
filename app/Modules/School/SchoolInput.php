<?php

declare(strict_types=1);

namespace Furoeducare\Modules\School;

use Furoeducare\Core\Request;
use Furoeducare\Modules\Auth\AuthInput;
use Furoeducare\Support\NigerianStates;

final class SchoolInput
{
    public const CATEGORIES = [
        'early_years' => 'Early years',
        'primary' => 'Primary school',
        'secondary' => 'Secondary school',
        'combined' => 'Combined primary and secondary',
        'tertiary' => 'Tertiary institution',
        'technical' => 'Technical or vocational institution',
        'other' => 'Other educational institution',
    ];

    public const STATES = NigerianStates::NAMES;

    public const PROFILE_FIELDS = [
        'school_name', 'category', 'state_code', 'city', 'address',
        'contact_email', 'contact_phone', 'representative_name', 'representative_position',
    ];

    public static function profile(Request $request, bool $registration = false, bool $acceptance = false): array
    {
        $errors = [];
        $values = [
            'school_name' => self::text($request, 'school_name', 'school name', 160, $errors),
            'category' => self::choice($request, 'category', self::CATEGORIES, 'Choose a school category.', $errors),
            'state_code' => self::choice($request, 'state_code', self::STATES, 'Choose a state or the FCT.', $errors),
            'city' => self::text($request, 'city', 'city or town', 100, $errors),
            'address' => self::text($request, 'address', 'school address', 500, $errors, multiline: true),
            'contact_email' => self::email($request, 'contact_email', 'Enter the school’s contact email.', $errors),
            'contact_phone' => AuthInput::phone($request->form('contact_phone')),
            'representative_name' => self::text($request, 'representative_name', 'representative’s name', 120, $errors),
            'representative_position' => self::text($request, 'representative_position', 'representative’s position', 100, $errors),
        ];
        if ($values['contact_phone'] === null) {
            $errors['contact_phone'] = 'Enter a Nigerian number or include the international calling code.';
            $values['contact_phone'] = substr($request->form('contact_phone'), 0, 48);
        }
        foreach (['school_name', 'city'] as $key) {
            if (preg_match('/[\p{L}\p{N}]/u', $values[$key]) !== 1) {
                $errors[$key] ??= 'Include a letter or number.';
            }
        }
        if ($registration) {
            $values['email'] = self::email($request, 'email', 'Enter the representative’s email address.', $errors);
            $errors += AuthInput::passwordErrors($request->form('password'), $request->form('password_confirmation'));
        }
        if ($acceptance || $registration) {
            foreach (['terms' => 'Accept the Terms of Use.', 'privacy' => 'Acknowledge the Privacy Notice.'] as $key => $message) {
                $values[$key] = $request->form($key) === '1' ? '1' : '';
                if ($values[$key] === '') {
                    $errors[$key] = $message;
                }
            }
        }
        return [$values, $errors];
    }

    public static function institutionKey(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}\p{M}]/u', '', $value) ?? '';
    }

    public static function draft(Request $request): array
    {
        $errors = [];
        $values = [
            'title' => self::text($request, 'title', 'draft title', 120, $errors),
            'requirements' => self::text($request, 'requirements', 'recruitment requirements', 4000, $errors, multiline: true, required: false),
        ];
        return [$values, $errors];
    }

    public static function decision(Request $request): array
    {
        $errors = [];
        $status = SchoolStatus::tryFrom($request->form('decision'));
        if ($status === null || !in_array($status, SchoolStatus::reviewDecisions(), true)) {
            $errors['decision'] = 'Choose a review decision.';
        }
        $reason = self::text($request, 'reason', 'decision reason', 1500, $errors, multiline: true);
        return [$status, $reason, $errors];
    }

    private static function choice(Request $request, string $field, array $options, string $message, array &$errors): string
    {
        $value = $request->form($field);
        if (!isset($options[$value])) {
            $errors[$field] = $message;
            return '';
        }
        return $value;
    }

    private static function email(Request $request, string $field, string $message, array &$errors): string
    {
        $raw = $request->form($field);
        $email = AuthInput::email($raw);
        if ($email === null) {
            $errors[$field] = $message;
        }
        return $email ?? substr($raw, 0, 254);
    }

    private static function text(
        Request $request, string $field, string $label, int $max, array &$errors,
        bool $multiline = false, bool $required = true,
    ): string {
        $input = $request->form($field);
        $value = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $input) ?? trim($input);
        $plain = $multiline ? str_replace(["\r", "\n", "\t"], '', $value) : $value;
        if ($value === '' && $required) {
            $errors[$field] = "Enter the $label.";
        } elseif (preg_match('/\A.{0,' . $max . '}\z/us', $value) !== 1 || preg_match('/\p{C}/u', $plain) !== 0) {
            $errors[$field] = "Use up to $max characters for the $label.";
        }
        return substr($value, 0, $max * 4);
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

use Furoeducare\Core\Request;
use Furoeducare\Modules\Auth\AuthInput;

final class TeacherInput
{
    public const TERMS = 'terms-draft-2026-11-16';
    public const PRIVACY = 'privacy-draft-2026-11-16';
    public const EMPLOYMENT = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'relief' => 'Relief teaching'];
    public const TUTORING = ['online' => 'Online', 'in_person' => 'In person'];
    public const AVAILABILITY = ['now' => 'Available now', 'from_date' => 'Available from a date', 'unavailable' => 'Currently unavailable'];
    public const ROW_LIMIT = 25;

    public static function registration(Request $request, bool $email, bool $password): array
    {
        [$values, $errors] = self::personal($request);
        $values['category'] = $request->form('category');
        if (!in_array($values['category'], ['teacher', 'nysc'], true)) {
            $errors['category'] = 'Choose Teacher or NYSC Member.';
        }
        foreach (['terms' => 'Accept the Terms of Use to continue.', 'privacy' => 'Acknowledge the Privacy Notice to continue.'] as $key => $message) {
            $values[$key] = $request->form($key) === '1' ? '1' : '';
            if ($values[$key] !== '1') {
                $errors[$key] = $message;
            }
        }
        if ($email) {
            $values['email'] = AuthInput::email($request->form('email')) ?? '';
            if ($values['email'] === '') {
                $errors['email'] = 'Enter a valid email address.';
            }
        }
        if ($password) {
            $errors += AuthInput::passwordErrors($request->form('password'), $request->form('password_confirmation'));
        }
        return [$values, $errors];
    }

    public static function section(Section $section, Request $request, string $category): array
    {
        if ($section === Section::Personal) {
            [$values, $errors] = self::personal($request);
            if ($category === 'nysc') {
                $values['nysc'] = [
                    'batch' => self::bounded($request->form('nysc_batch'), 40, 'nysc_batch', $errors),
                    'state' => self::bounded($request->form('nysc_state'), 80, 'nysc_state', $errors),
                    'year' => self::year($request->form('nysc_year'), 'nysc_year', $errors),
                ];
            }
            return [$values, $errors];
        }
        if ($section === Section::Qualifications || $section === Section::Experience) {
            return self::rows($section, $request);
        }

        $errors = [];
        $values = match ($section) {
            Section::Biography => ['bio' => self::bounded($request->form('bio'), 2500, 'bio', $errors, true)],
            Section::Subjects => [
                'subjects' => self::list($request->form('subjects'), 'subjects', $errors),
                'levels' => self::list($request->form('levels'), 'levels', $errors),
            ],
            Section::Skills => [
                'skills' => self::list($request->form('skills'), 'skills', $errors),
                'certifications' => self::list($request->form('certifications'), 'certifications', $errors),
            ],
            Section::Locations => ['locations' => self::list($request->form('locations'), 'locations', $errors)],
            Section::Preferences => [
                'employment' => self::choices($request->formArray('employment'), self::EMPLOYMENT, 'employment', $errors),
                'tutoring' => self::choices($request->formArray('tutoring'), self::TUTORING, 'tutoring', $errors),
            ],
            Section::Availability => self::availability($request, $errors),
            default => [],
        };
        return [$values, $errors];
    }

    private static function personal(Request $request): array
    {
        $errors = [];
        $first = self::bounded($request->form('first_name'), 80, 'first_name', $errors);
        $last = self::bounded($request->form('last_name'), 80, 'last_name', $errors);
        if ($first === '') {
            $errors['first_name'] ??= 'Enter your first name.';
        }
        if ($last === '') {
            $errors['last_name'] ??= 'Enter your last name.';
        }
        $phoneInput = trim($request->form('phone'));
        $phone = preg_replace('/[()\s-]/', '', $phoneInput);
        if (preg_match('/\A0[0-9]{10}\z/', $phone) === 1) {
            $phone = '+234' . substr($phone, 1);
        } elseif (preg_match('/\A234[0-9]{10}\z/', $phone) === 1) {
            $phone = '+' . $phone;
        }
        if (preg_match('/\A\+[1-9][0-9]{6,14}\z/', $phone) !== 1) {
            $errors['phone'] = 'Enter an 11-digit Nigerian number or a number with its international calling code.';
        }
        return [['first_name' => $first, 'last_name' => $last, 'phone' => isset($errors['phone']) ? substr($phoneInput, 0, 48) : $phone], $errors];
    }

    private static function rows(Section $section, Request $request): array
    {
        $education = $section === Section::Qualifications;
        $submitted = $request->formArray('rows');
        $errors = [];
        if (count($submitted) > self::ROW_LIMIT) {
            $errors['rows'] = 'Save up to 25 entries in this section.';
        }
        $rows = [];
        foreach (array_slice($submitted, 0, self::ROW_LIMIT) as $entry) {
            if (!is_array($entry)) {
                $errors['rows'] = 'Review the entries in this section.';
                continue;
            }
            if (($entry['remove'] ?? null) === '1') {
                continue;
            }
            $index = count($rows);
            $string = static fn (string $key): string => is_string($entry[$key] ?? null) ? $entry[$key] : '';
            $row = [];
            $fields = $education
                ? ['qualification' => 160, 'institution' => 160, 'field_of_study' => 160]
                : ['position_title' => 160, 'organisation' => 160, 'description' => 1000];
            foreach ($fields as $key => $max) {
                $row[$key] = self::bounded($string($key), $max, "rows.$index.$key", $errors, $key === 'description');
            }
            foreach ($education ? ['completed_year'] : ['started_year', 'ended_year'] as $key) {
                $row[$key] = self::year($string($key), "rows.$index.$key", $errors);
            }
            if (!$education && $row['started_year'] !== null && $row['ended_year'] !== null
                && is_int($row['started_year']) && is_int($row['ended_year']) && $row['ended_year'] < $row['started_year']) {
                $errors["rows.$index.ended_year"] = 'The end year cannot be before the start year.';
            }
            if (array_filter($row, static fn ($value): bool => $value !== '' && $value !== null) !== []) {
                $rows[] = $row;
            }
        }
        $none = !$education && $request->form('no_experience') === '1';
        if ($none && $rows !== []) {
            $errors['no_experience'] = 'Clear this option or remove the work entries.';
        }
        return [['rows' => $rows, 'no_experience' => $none], $errors];
    }

    private static function availability(Request $request, array &$errors): array
    {
        $status = $request->form('status');
        $from = $status === 'from_date' ? $request->form('date') : '';
        if ($status !== '' && !isset(self::AVAILABILITY[$status])) {
            $errors['status'] = 'Choose an availability option from the list.';
        }
        if ($from !== '' && (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $from, $parts) !== 1
            || (int) $parts[1] < 1900 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
            $errors['date'] = 'Enter a valid date.';
        }
        return ['status' => $status, 'date' => $from];
    }

    private static function choices(array $items, array $choices, string $field, array &$errors): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_string($item) || !isset($choices[$item])) {
                $errors[$field] = 'Select only options shown in the form.';
                continue;
            }
            $result[$item] = $item;
        }
        return array_values($result);
    }

    private static function list(string $input, string $field, array &$errors): array
    {
        $input = self::bounded($input, 2500, $field, $errors, true);
        $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $input)), 'strlen')));
        if (count($items) > 25) {
            $errors[$field] = 'Enter up to 25 items.';
        }
        foreach ($items as $item) {
            self::bounded($item, 100, $field, $errors);
        }
        return array_slice($items, 0, 25);
    }

    private static function bounded(string $value, int $max, string $field, array &$errors, bool $multiline = false): string
    {
        $value = trim($value);
        $lengthOkay = preg_match('/\A.{0,' . $max . '}\z/us', $value) === 1;
        $controls = $multiline ? preg_match('/\p{C}/u', str_replace(["\r", "\n", "\t"], '', $value)) : preg_match('/\p{C}/u', $value);
        if (!$lengthOkay || $controls !== 0) {
            $errors[$field] = "Use up to $max characters without control characters.";
        }
        return strlen($value) > $max * 4 ? substr($value, 0, $max * 4) : $value;
    }

    private static function year(string $value, string $field, array &$errors): int|string|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A[0-9]{4}\z/', $value) !== 1 || (int) $value < 1900 || (int) $value > (int) gmdate('Y') + 10) {
            $errors[$field] = 'Enter a valid four-digit year.';
            return substr($value, 0, 20);
        }
        return (int) $value;
    }
}

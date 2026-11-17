<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Vacancy;

use DateTimeImmutable;
use DateTimeZone;
use Furoeducare\Core\Request;
use Furoeducare\Support\NigerianStates;

final class VacancyInput
{
    public const EMPLOYMENT = [
        'full_time' => 'Full time', 'part_time' => 'Part time', 'contract' => 'Contract', 'temporary' => 'Temporary',
    ];
    public const TEXT = [
        'title' => ['Position title', 140], 'subjects' => ['Subjects', 400], 'teaching_level' => ['Teaching level', 120],
        'qualification' => ['Required qualification', 200], 'city' => ['City or town', 100],
        'description' => ['Job description', 5000], 'responsibilities' => ['Responsibilities', 5000],
        'skills' => ['Required skills', 3000],
    ];
    public const NUMBERS = [
        'experience' => ['Minimum years of experience', 0, 70], 'positions' => ['Number of positions', 1, 10000],
        'salary_min' => ['Minimum salary in NGN', 0, 1000000000], 'salary_max' => ['Maximum salary in NGN', 0, 1000000000],
    ];
    public const LONG = ['description', 'responsibilities', 'skills'];

    public static function draft(Request $request): array
    {
        $fields = [];
        $errors = [];
        foreach (self::TEXT as $key => [$label, $length]) {
            $fields[$key] = trim(str_replace(["\r\n", "\r"], "\n", $request->form($key)));
            $multiline = in_array($key, self::LONG, true);
            if (preg_match('/\A.{0,' . $length . '}\z/us', $fields[$key]) !== 1
                || preg_match('/\p{C}/u', $multiline ? str_replace(["\n", "\t"], '', $fields[$key]) : $fields[$key]) !== 0) {
                $errors[$key] = "$label must use up to $length valid characters.";
            }
        }
        foreach (self::NUMBERS as $key => [$label, $min, $max]) {
            $fields[$key] = trim($request->form($key));
            if ($fields[$key] === '') {
                continue;
            }
            $number = filter_var($fields[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($number === false) {
                $errors[$key] = "$label must be a whole number between $min and $max.";
            } else {
                $fields[$key] = (string) $number;
            }
        }
        if (!isset($errors['salary_min']) && !isset($errors['salary_max']) && $fields['salary_min'] !== '' && $fields['salary_max'] !== ''
            && (int) $fields['salary_min'] > (int) $fields['salary_max']) {
            $errors['salary_max'] = 'The maximum salary must be at least the minimum salary.';
        }
        foreach (['state_code' => NigerianStates::NAMES, 'employment_type' => self::EMPLOYMENT] as $key => $options) {
            $fields[$key] = $request->form($key);
            if ($fields[$key] !== '' && !isset($options[$fields[$key]])) {
                $errors[$key] = 'Choose an option from the list.';
            }
        }
        $fields['deadline'] = trim($request->form('deadline'));
        if ($fields['deadline'] !== '' && !self::validDate($fields['deadline'])) {
            $errors['deadline'] = 'Enter a valid application deadline.';
        }
        return [$fields, $errors];
    }

    public static function submissionProblem(array $fields): ?string
    {
        foreach ([...array_keys(self::TEXT), 'experience', 'positions', 'state_code', 'employment_type', 'deadline'] as $key) {
            if (($fields[$key] ?? '') === '') {
                return 'Complete all required vacancy fields before submitting.';
            }
        }
        if (($fields['salary_min'] === '') !== ($fields['salary_max'] === '')) {
            return 'Enter both salary amounts or leave both blank.';
        }
        return $fields['deadline'] < self::today() ? 'Choose an application deadline that has not passed.' : null;
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
    }

    public static function note(string $note): ?string
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        return preg_match('/\A.{0,1000}\z/us', $note) === 1
            && preg_match('/\p{C}/u', str_replace(["\n", "\t"], '', $note)) === 0 ? $note : null;
    }

    private static function validDate(string $date): bool
    {
        return preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}

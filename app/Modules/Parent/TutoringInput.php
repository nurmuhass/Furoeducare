<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Parent;

use Furoeducare\Core\Request;
use Furoeducare\Modules\Directory\DirectoryFilters;
use Furoeducare\Support\NigerianStates;

final class TutoringInput
{
    public const LEVELS = [
        'early_years' => 'Early years', 'primary' => 'Primary', 'junior_secondary' => 'Junior secondary',
        'senior_secondary' => 'Senior secondary', 'tertiary' => 'Tertiary', 'adult' => 'Adult', 'other' => 'Other',
    ];
    public const MODES = ['home' => 'Home tutoring', 'online' => 'Online tutoring', 'either' => 'Home or online'];
    public const DAYS = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];

    public static function draft(Request $request): array
    {
        $errors = [];
        $subject = self::text($request->form('subject'), 'subject', 120, $errors);
        $requirements = self::text($request->form('requirements'), 'requirements', 2000, $errors, true);
        $details = [
            'objectives' => self::text($request->form('objectives'), 'objectives', 2000, $errors, true),
            'qualifications' => self::text($request->form('qualifications'), 'qualifications', 400, $errors),
            'city' => self::text($request->form('city'), 'city', 100, $errors),
        ];
        if ($details['city'] !== '' && (preg_match("/\\A[\\p{L}\\p{M}][\\p{L}\\p{M}.'’ -]*\\z/u", $details['city']) !== 1
            || preg_match('/\b(?:street|road|lane|avenue|close|crescent|drive|boulevard)\b/i', $details['city']) === 1)) {
            $errors['city'] = 'Use a city or general area, such as Ikeja.';
        }
        foreach (['state_code' => NigerianStates::NAMES, 'mode' => self::MODES, 'level' => self::LEVELS] as $key => $options) {
            $details[$key] = $request->form($key);
            if ($details[$key] !== '' && !isset($options[$details[$key]])) {
                $errors[$key] = 'Choose an option from the list.';
            }
        }
        foreach (['learners' => 30, 'sessions' => 14] as $key => $max) {
            $details[$key] = trim($request->form($key));
            if ($details[$key] !== '' && filter_var($details[$key], FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => $max]]) === false) {
                $errors[$key] = "Enter a whole number from 1 to $max.";
            }
        }
        $days = $request->formArray('days');
        $details['days'] = [];
        foreach ($days as $day) {
            if (!is_string($day) || !isset(self::DAYS[$day]) || in_array($day, $details['days'], true)) {
                $errors['days'] = 'Choose valid lesson days.';
                continue;
            }
            $details['days'][] = $day;
        }
        $details['times'] = self::times($request, $errors);
        return [compact('subject', 'requirements', 'details'), $errors];
    }

    public static function missing(array $record): array
    {
        $details = $record['details'];
        $required = [
            'subject' => 'a subject', 'level' => 'the education level', 'learners' => 'number of learners',
            'objectives' => 'learning objectives', 'mode' => 'lesson format', 'days' => 'preferred days',
            'times' => 'preferred times', 'sessions' => 'sessions per week',
        ];
        if (in_array($details['mode'] ?? '', ['home', 'either'], true)) {
            $required += ['state_code' => 'the state', 'city' => 'a general location'];
        }
        $values = ['subject' => $record['subject']] + $details;
        return array_values(array_filter($required, static fn (string $key): bool => empty($values[$key]), ARRAY_FILTER_USE_KEY));
    }

    public static function modeMask(string $mode): int
    {
        $home = DirectoryFilters::MODES['private_tutoring'];
        $online = DirectoryFilters::MODES['online_tutoring'];
        return match ($mode) {
            'home' => $home,
            'online' => $online,
            default => $home | $online,
        };
    }

    public static function validVersion(string $value): bool
    {
        return preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/', $value) === 1;
    }

    private static function times(Request $request, array &$errors): array
    {
        $starts = array_values($request->formArray('time_from'));
        $ends = array_values($request->formArray('time_to'));
        if (count($starts) > 3 || count($ends) > 3 || count($starts) !== count($ends)) {
            $errors['times'] = 'Enter up to three complete time ranges.';
            return [];
        }
        $times = [];
        foreach ($starts as $index => $from) {
            $to = $ends[$index];
            if ($from === '' && $to === '') {
                continue;
            }
            if (!is_string($from) || !is_string($to) || !self::clock($from) || !self::clock($to) || $from >= $to) {
                $errors['times'] = 'Use a start time that comes before the end time for each range.';
            }
            $times[] = ['from' => is_string($from) ? $from : '', 'to' => is_string($to) ? $to : ''];
        }
        return $times;
    }

    private static function clock(string $value): bool
    {
        return preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $value) === 1;
    }

    private static function text(string $input, string $key, int $limit, array &$errors, bool $multiline = false): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $input));
        if (preg_match('/\A.{0,' . $limit . '}\z/us', $value) !== 1
            || preg_match('/\p{C}/u', $multiline ? str_replace(["\n", "\t"], '', $value) : $value) !== 0) {
            $errors[$key] = "Use up to $limit characters and remove any invalid characters.";
        }
        return strlen($value) <= 4 * $limit ? $value : '';
    }
}

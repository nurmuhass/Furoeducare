<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Training;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class TrainingInput
{
    public const FORMATS = ['on_site', 'online', 'hybrid'];
    public const MODULE_FIELDS = ['title', 'description', 'category', 'objectives', 'target_participants',
        'duration', 'delivery_format', 'prerequisites', 'materials_metadata'];

    public static function module(array $input): array
    {
        $values = [];
        foreach (['title' => 200, 'description' => 6000, 'category' => 120, 'objectives' => 4000,
            'target_participants' => 300, 'duration' => 120, 'prerequisites' => 2000, 'materials_metadata' => 2000] as $name => $limit) {
            $values[$name] = self::text($input[$name] ?? '', $name, $limit, !in_array($name, ['prerequisites', 'materials_metadata'], true));
        }
        $values['delivery_format'] = self::format($input['delivery_format'] ?? '');
        return $values;
    }

    public static function text(mixed $value, string $name, int $limit, bool $required = true): string
    {
        if (!is_string($value)) {
            throw new DomainException('Enter valid text for ' . str_replace('_', ' ', $name) . '.', 422);
        }
        $value = trim($value);
        if (($required && $value === '') || preg_match('/\A.{0,' . $limit . '}\z/us', $value) !== 1
            || preg_match('/[^\P{Cc}\r\n\t]/u', $value) !== 0) {
            throw new DomainException('Enter ' . str_replace('_', ' ', $name) . ' using up to ' . $limit . ' characters.', 422);
        }
        return $value;
    }

    public static function ids(array $input): array
    {
        if (count($input) < 1 || count($input) > 20) {
            throw new DomainException('Choose between one and twenty modules.', 422);
        }
        $ids = [];
        foreach ($input as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || isset($ids[$id])) {
                throw new DomainException('Choose distinct valid modules.', 422);
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    public static function request(array $input): array
    {
        $participants = filter_var($input['participants'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($participants === false) {
            throw new DomainException('Enter a participant count between 1 and 10,000.', 422);
        }
        $dates = [];
        $zone = new DateTimeZone('Africa/Lagos');
        foreach (preg_split('/\R/u', self::text($input['preferred_dates'] ?? '', 'preferred dates', 120)) as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
            if ($date === false || $date->format('Y-m-d') !== $value || $date <= new DateTimeImmutable('today', $zone)) {
                throw new DomainException('Enter future preferred dates, one YYYY-MM-DD date per line.', 422);
            }
            $dates[$value] = $value;
        }
        if ($dates === [] || count($dates) > 10) {
            throw new DomainException('Provide one to ten preferred dates.', 422);
        }
        sort($dates);
        return ['participants' => $participants, 'location' => self::text($input['location'] ?? '', 'location', 500),
            'preferred_dates' => $dates, 'requirements' => self::text($input['requirements'] ?? '', 'requirements', 4000, false)];
    }

    public static function schedule(array $input): array
    {
        $zone = new DateTimeZone('Africa/Lagos');
        $dates = [];
        foreach (['starts_at', 'ends_at'] as $key) {
            $raw = $input[$key] ?? '';
            $date = is_string($raw) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, $zone) : false;
            if ($date === false || $date->format('Y-m-d\TH:i') !== $raw) {
                throw new DomainException('Enter valid session start and end times in WAT.', 422);
            }
            $dates[$key] = $date;
        }
        if ($dates['starts_at'] <= new DateTimeImmutable('now', $zone) || $dates['ends_at'] <= $dates['starts_at']) {
            throw new DomainException('Start must be in the future and end must follow start.', 422);
        }
        $utc = new DateTimeZone('UTC');
        return ['scheduled_start' => $dates['starts_at']->setTimezone($utc)->format('Y-m-d H:i:s'),
            'scheduled_end' => $dates['ends_at']->setTimezone($utc)->format('Y-m-d H:i:s'),
            'session_format' => self::format($input['delivery_format'] ?? ''),
            'session_location' => self::text($input['session_location'] ?? '', 'session location', 500)];
    }

    private static function format(mixed $value): string
    {
        return in_array($value, self::FORMATS, true) ? $value : throw new DomainException('Choose on-site, online or hybrid delivery.', 422);
    }
}

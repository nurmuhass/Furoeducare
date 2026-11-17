<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher\Cv;

use InvalidArgumentException;

final class CvDraft
{
    public const VERSION = 1;
    public const LIMIT = 25;
    public const QUALIFICATION = ['qualification' => 160, 'institution' => 160, 'field_of_study' => 160, 'completed_year' => 0];
    public const EXPERIENCE = ['position_title' => 160, 'organisation' => 160, 'started_year' => 0, 'ended_year' => 0, 'description' => 1000];

    public static function validate(array $draft): array
    {
        $keys = ['version', 'summary', 'qualifications', 'experience', 'skills', 'subjects', 'certifications'];
        self::keys($draft, $keys);
        if ($draft['version'] !== self::VERSION || !self::text($draft['summary'], 2500, multiline: true)) {
            throw new InvalidArgumentException('Invalid CV draft version or summary.');
        }

        foreach (['qualifications' => self::QUALIFICATION, 'experience' => self::EXPERIENCE] as $key => $fields) {
            foreach (self::items($draft[$key]) as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('Invalid CV draft entry.');
                }
                self::keys($row, array_keys($fields));
                foreach ($fields as $field => $length) {
                    $value = $row[$field];
                    $valid = $length > 0 ? self::text($value, $length, multiline: $field === 'description')
                        : ($value === null || (is_int($value) && $value >= 1900 && $value <= (int) gmdate('Y') + 10));
                    if (!$valid) {
                        throw new InvalidArgumentException('Invalid CV draft field.');
                    }
                }
                if (array_filter($row, static fn ($value): bool => $value !== null) === []
                    || (isset($row['started_year'], $row['ended_year']) && $row['started_year'] > $row['ended_year'])) {
                    throw new InvalidArgumentException('Invalid CV draft entry values.');
                }
            }
        }

        foreach (['skills', 'subjects', 'certifications'] as $key) {
            foreach (self::items($draft[$key]) as $item) {
                if ($item === null || !self::text($item, 100)) {
                    throw new InvalidArgumentException('Invalid CV draft list item.');
                }
            }
        }
        return $draft;
    }

    private static function keys(array $values, array $keys): void
    {
        if (count($values) !== count($keys) || array_diff_key(array_flip($keys), $values) !== []) {
            throw new InvalidArgumentException('Unexpected CV draft properties.');
        }
    }

    private static function items(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > self::LIMIT) {
            throw new InvalidArgumentException('Invalid CV draft list.');
        }
        return $items;
    }

    private static function text(mixed $value, int $length, bool $multiline = false): bool
    {
        return $value === null || (is_string($value) && trim($value) !== ''
            && preg_match('/\A.{1,' . $length . '}\z/us', $value) === 1
            && preg_match('/\p{C}/u', $multiline ? str_replace(["\r", "\n", "\t"], '', $value) : $value) === 0);
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Vacancy;

final class ApplicationData
{
    public const STATEMENT_LIMIT = 2000;

    public static function statement(string $value): ?string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (preg_match('/\A.{0,' . self::STATEMENT_LIMIT . '}\z/us', $value) !== 1
            || preg_match('/\p{C}/u', str_replace(["\n", "\t"], '', $value)) !== 0) {
            return null;
        }
        return $value;
    }

    /** Capture the professional fields shown in the application confirmation. */
    public static function profile(array $profile): array
    {
        $sections = $profile['sections'];
        $qualificationFields = array_flip(['qualification', 'institution', 'field_of_study', 'completed_year']);
        $experienceFields = array_flip(['position_title', 'organisation', 'started_year', 'ended_year']);
        return [
            'category' => $profile['category'],
            'subjects' => self::items($sections['subjects']['subjects'] ?? []),
            'levels' => self::items($sections['subjects']['levels'] ?? []),
            'skills' => self::items($sections['skills']['skills'] ?? []),
            'certifications' => self::items($sections['skills']['certifications'] ?? []),
            'qualifications' => array_map(static fn (array $entry): array => array_intersect_key($entry, $qualificationFields),
                $sections['qualifications']['rows']),
            'experience' => array_map(static fn (array $entry): array => array_intersect_key($entry, $experienceFields),
                $sections['experience']['rows']),
        ];
    }

    /** Profiles saved before the list fields were introduced may still contain a single string. */
    private static function items(array|string $value): array
    {
        return array_values(array_filter((array) $value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

final class ProfileCompletion
{
    /** @return array<string, list<string>> Missing requirements by section. */
    public static function missing(array $profile): array
    {
        $sections = $profile['sections'];
        $personal = self::fields($profile, ['first_name' => 'First name', 'last_name' => 'Last name', 'phone' => 'Phone number']);
        $employment = $sections['preferences']['employment'] ?? [];
        $tutoring = $sections['preferences']['tutoring'] ?? [];
        $experience = $sections['experience'] ?? [];
        $availability = $sections['availability'] ?? [];
        $documents = $sections['documents']['files'] ?? [];

        $missing = [
            'personal' => $personal,
            'biography' => self::fields($sections['biography'] ?? [], ['bio' => 'Professional biography']),
            'qualifications' => self::entries(
                $sections['qualifications']['rows'] ?? [], ['qualification' => 'qualification', 'institution' => 'institution'],
                'Add a qualification.'
            ),
            'experience' => !empty($experience['no_experience']) ? [] : self::entries(
                $experience['rows'] ?? [], ['position_title' => 'role or position', 'organisation' => 'organisation'],
                'Add a work experience or indicate that you have none yet.'
            ),
            'subjects' => self::fields($sections['subjects'] ?? [], ['subjects' => 'At least one subject', 'levels' => 'At least one teaching level']),
            'skills' => self::fields($sections['skills'] ?? [], ['skills' => 'At least one skill']),
            'locations' => self::fields($sections['locations'] ?? [], ['locations' => 'At least one preferred location']),
            'preferences' => $employment === [] && $tutoring === [] ? ['At least one employment or tutoring preference'] : [],
            'availability' => self::fields($availability, ['status' => 'Availability status']),
            'documents' => in_array('cv', array_column($documents, 'kind'), true) ? [] : ['Upload a CV.'],
        ];
        if (($availability['status'] ?? '') === 'from_date' && ($availability['date'] ?? '') === '') {
            $missing['availability'][] = 'Available from date';
        }
        return $missing;
    }

    private static function fields(array $data, array $labels): array
    {
        return array_values(array_filter($labels, static fn (string $key): bool => empty($data[$key]), ARRAY_FILTER_USE_KEY));
    }

    private static function entries(array $rows, array $labels, string $emptyMessage): array
    {
        if ($rows === []) {
            return [$emptyMessage];
        }

        $missing = [];
        foreach ($rows as $index => $row) {
            foreach (self::fields($row, $labels) as $field) {
                $missing[] = 'Entry ' . ($index + 1) . ' needs ' . $field . '.';
            }
        }
        return $missing;
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Directory;

use Furoeducare\Core\Request;
use Furoeducare\Support\NigerianStates;

final class DirectoryFilters
{
    public const MODES = ['full_time' => 1, 'part_time' => 2, 'private_tutoring' => 4, 'online_tutoring' => 8];
    public const MODE_LABELS = [
        'full_time' => 'Full-time teaching', 'part_time' => 'Part-time teaching',
        'private_tutoring' => 'Home tutoring', 'online_tutoring' => 'Online tutoring',
    ];
    public const TUTORING = ['home' => 'private_tutoring', 'online' => 'online_tutoring'];

    public static function fromRequest(Request $request): array
    {
        $filters = [];
        foreach (['q', 'subject', 'level', 'city', 'qualification'] as $key) {
            $value = trim($request->query($key));
            if (preg_match('/\A[^\p{C}]{1,100}\z/u', $value) === 1) {
                $filters[$key] = $value;
            }
        }
        foreach (['state' => NigerianStates::NAMES, 'availability' => self::MODES, 'tutoring' => self::TUTORING] as $key => $choices) {
            $value = $request->query($key);
            if (isset($choices[$value])) {
                $filters[$key] = $value;
            }
        }
        $experience = $request->query('experience');
        if (preg_match('/\A(?:[0-9]|[1-6][0-9]|70)\z/', $experience) === 1) {
            $filters['experience'] = $experience;
        }
        return $filters;
    }

    public static function reference(string $value): ?string
    {
        return preg_match('/\AFT-[A-F0-9]{20}\z/', $value) === 1 ? $value : null;
    }

    public static function labels(int $mask): array
    {
        $labels = [];
        foreach (self::MODES as $key => $bit) {
            if (($mask & $bit) !== 0) {
                $labels[] = self::MODE_LABELS[$key];
            }
        }
        return $labels;
    }
}

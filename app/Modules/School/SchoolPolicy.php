<?php

declare(strict_types=1);

namespace Furoeducare\Modules\School;

final class SchoolPolicy
{
    public static function profileComplete(array $school): bool
    {
        return $school['institution_name_key'] !== null && $school['accepted_at'] !== null;
    }

    public static function canRecruit(array $school): bool
    {
        return self::profileComplete($school) && SchoolStatus::forSchool($school) === SchoolStatus::Approved;
    }
}

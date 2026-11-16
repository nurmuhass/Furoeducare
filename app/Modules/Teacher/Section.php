<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

enum Section: string
{
    case Personal = 'personal';
    case Biography = 'biography';
    case Qualifications = 'qualifications';
    case Experience = 'experience';
    case Subjects = 'subjects';
    case Skills = 'skills';
    case Locations = 'locations';
    case Preferences = 'preferences';
    case Availability = 'availability';
    case Documents = 'documents';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personal and contact information',
            self::Biography => 'Professional biography',
            self::Qualifications => 'Education and qualifications',
            self::Experience => 'Work and teaching experience',
            self::Subjects => 'Subjects and teaching levels',
            self::Skills => 'Skills and certifications',
            self::Locations => 'Preferred locations',
            self::Preferences => 'Employment and tutoring preferences',
            self::Availability => 'Availability',
            self::Documents => 'CV and professional documents',
        };
    }
}

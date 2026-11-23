<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

enum DashboardPage: string
{
    case Overview = 'overview';
    case Profile = 'profile';
    case Documents = 'documents';
    case Jobs = 'jobs';
    case Applications = 'applications';
    case Placements = 'placements';
    case Availability = 'availability';
    case SavedJobs = 'saved-jobs';
    case Library = 'library';
    case Messages = 'messages';
    case Notifications = 'notifications';
    case Settings = 'settings';

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Profile => 'My Professional Profile',
            self::Documents => 'CV and Documents',
            self::Jobs => 'Teaching Jobs',
            self::Applications => 'My Applications',
            self::Placements => 'Placement Opportunities',
            self::Availability => 'Availability',
            self::SavedJobs => 'Saved Jobs',
            self::Library => 'Digital Library',
            self::Messages => 'Messages',
            self::Notifications => 'Notifications',
            self::Settings => 'Settings',
        };
    }

    public function url(): string
    {
        if ($this === self::Notifications) {
            return '/notifications';
        }
        if ($this === self::Messages) {
            return '/messages';
        }
        if ($this === self::Library) {
            return '/digital-library';
        }
        return '/dashboard/teacher' . ($this === self::Overview ? '' : '?tab=' . $this->value);
    }

    public static function forSection(Section $section): self
    {
        return match ($section) {
            Section::Documents => self::Documents,
            Section::Availability => self::Availability,
            default => self::Profile,
        };
    }

    public function pendingMessage(): string
    {
        return match ($this) {
            self::Library => 'Browse published books and learning materials in the digital library.',
        };
    }
}

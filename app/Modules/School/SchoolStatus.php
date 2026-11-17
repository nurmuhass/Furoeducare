<?php

declare(strict_types=1);

namespace Furoeducare\Modules\School;

enum SchoolStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case CorrectionsRequired = 'corrections_required';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::CorrectionsRequired => 'Changes required',
            self::Suspended => 'Suspended',
        };
    }

    public static function forSchool(array $school): self
    {
        return self::from($school['review_status'] ?? ($school['approved_at'] === null ? 'pending' : 'approved'));
    }

    /** @return list<self> */
    public static function reviewDecisions(): array
    {
        return [self::Approved, self::Rejected, self::CorrectionsRequired, self::Suspended];
    }

    /** @return list<self> */
    public function allowedDecisions(): array
    {
        return match ($this) {
            self::Pending => self::reviewDecisions(),
            self::Approved => [self::Rejected, self::CorrectionsRequired, self::Suspended],
            self::CorrectionsRequired => [self::Rejected, self::Suspended],
            self::Rejected => [self::Approved, self::CorrectionsRequired, self::Suspended],
            self::Suspended => [self::Approved, self::Rejected, self::CorrectionsRequired],
        };
    }
}

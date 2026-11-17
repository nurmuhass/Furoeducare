<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Verification;

enum TeacherStatus: string
{
    case Incomplete = 'incomplete';
    case Pending = 'pending';
    case Verified = 'verified';
    case CorrectionsRequired = 'corrections_required';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Incomplete => 'Incomplete',
            self::Pending => 'Pending verification',
            self::Verified => 'Verified',
            self::CorrectionsRequired => 'Changes required',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    /** @return list<self> */
    public function staffDecisions(): array
    {
        return match ($this) {
            self::Incomplete => [self::Suspended],
            self::Pending => [self::Verified, self::CorrectionsRequired, self::Rejected, self::Suspended],
            self::Verified => [self::CorrectionsRequired, self::Rejected, self::Suspended],
            self::CorrectionsRequired => [self::Rejected, self::Suspended],
            self::Rejected => [self::Pending, self::Suspended],
            self::Suspended => [self::Pending],
        };
    }
}

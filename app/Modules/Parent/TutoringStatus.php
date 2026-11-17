<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Parent;

enum TutoringStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Matching = 'matching';
    case Shortlisted = 'shortlisted';
    case ArrangementPending = 'arrangement_pending';
    case Confirmed = 'confirmed';
    case Closed = 'closed';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function staffTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Closed],
            self::UnderReview => [self::Matching, self::Closed],
            self::Matching => [self::Shortlisted, self::Closed],
            self::Shortlisted => [self::ArrangementPending, self::Closed],
            self::ArrangementPending => [self::Confirmed, self::Closed],
            self::Draft, self::Confirmed, self::Closed => [],
        };
    }

    public function canManageCandidates(): bool
    {
        return in_array($this, [self::Matching, self::Shortlisted, self::ArrangementPending], true);
    }
}

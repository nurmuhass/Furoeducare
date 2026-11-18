<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Placement;

enum PlacementStatus: string
{
    case Screening = 'screening';
    case Shortlisted = 'shortlisted';
    case DiscussionScheduled = 'discussion_scheduled';
    case AvailabilityConfirmed = 'availability_confirmed';
    case Selected = 'selected';
    case OfferPending = 'offer_pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public function after(string $action): ?self
    {
        if ($action === 'reject' && !in_array($this, [self::Confirmed, self::Rejected, self::Closed], true)) {
            return self::Rejected;
        }
        return match ([$this, $action]) {
            [self::Screening, 'shortlist'] => self::Shortlisted,
            [self::Shortlisted, 'schedule'], [self::DiscussionScheduled, 'schedule'] => self::DiscussionScheduled,
            [self::DiscussionScheduled, 'available'] => self::AvailabilityConfirmed,
            [self::AvailabilityConfirmed, 'select'] => self::Selected,
            [self::Selected, 'offer'], [self::OfferPending, 'safeguard'] => self::OfferPending,
            [self::OfferPending, 'confirm'] => self::Confirmed,
            [self::Confirmed, 'close'] => self::Closed,
            default => null,
        };
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}

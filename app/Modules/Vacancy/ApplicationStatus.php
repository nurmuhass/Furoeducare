<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Vacancy;

enum ApplicationStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Shortlisted = 'shortlisted';
    case InterviewInvited = 'interview_invited';
    case OfferPending = 'offer_pending';
    case Successful = 'successful';
    case Unsuccessful = 'unsuccessful';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /** @return list<self> */
    public function transitions(bool $staff): array
    {
        if (!$staff) {
            return $this->transitions(true) === [] ? [] : [self::Withdrawn];
        }
        return match ($this) {
            self::Submitted => [self::UnderReview],
            self::UnderReview => [self::Shortlisted, self::Unsuccessful],
            self::Shortlisted => [self::InterviewInvited, self::Unsuccessful],
            self::InterviewInvited => [self::OfferPending, self::Unsuccessful],
            self::OfferPending => [self::Successful, self::Unsuccessful],
            self::Successful, self::Unsuccessful, self::Withdrawn => [],
        };
    }

    public static function fromStored(string $status): self
    {
        return $status === 'rejected' ? self::Unsuccessful : self::from($status);
    }
}

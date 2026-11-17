<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Vacancy;

enum VacancyStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Published = 'published';
    case Closed = 'closed';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function actions(bool $staff, bool $approvedSchool): array
    {
        if ($staff) {
            return match ($this) {
                self::Submitted => ['start_review' => self::UnderReview],
                self::UnderReview => ['approve' => self::Approved, 'reject' => self::Rejected],
                self::Approved => ['publish' => self::Published],
                self::Published => ['close' => self::Closed],
                default => [],
            };
        }
        return match ($this) {
            self::Draft => $approvedSchool ? ['submit' => self::Submitted] : [],
            self::Published => ['close' => self::Closed],
            default => [],
        };
    }

    public function editable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected, self::Approved, self::Published], true);
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Training;

final class TrainingWorkflow
{
    public const STATUSES = ['submitted', 'under_review', 'discussion', 'scheduled', 'completed', 'cancelled'];

    public static function allows(string $status, string $action): bool
    {
        return in_array($status, match ($action) {
            'under_review' => ['submitted'],
            'discussion' => ['under_review'],
            'approve', 'schedule' => ['discussion'],
            'assign' => ['submitted', 'under_review', 'discussion'],
            'reschedule', 'complete' => ['scheduled'],
            'cancel' => ['submitted', 'under_review', 'discussion', 'scheduled'],
            default => [],
        }, true);
    }
}

<?php

declare(strict_types=1);

use Furoeducare\Modules\Placement\PlacementStatus;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

$steps = [
    'screening' => ['shortlist' => 'shortlisted', 'reject' => 'rejected'],
    'shortlisted' => ['schedule' => 'discussion_scheduled', 'reject' => 'rejected'],
    'discussion_scheduled' => ['schedule' => 'discussion_scheduled', 'available' => 'availability_confirmed', 'reject' => 'rejected'],
    'availability_confirmed' => ['select' => 'selected', 'reject' => 'rejected'],
    'selected' => ['offer' => 'offer_pending', 'reject' => 'rejected'],
    'offer_pending' => ['safeguard' => 'offer_pending', 'confirm' => 'confirmed', 'reject' => 'rejected'],
    'confirmed' => ['close' => 'closed'],
    'rejected' => [],
    'closed' => [],
];
$actions = ['shortlist', 'schedule', 'available', 'select', 'offer', 'safeguard', 'confirm', 'reject', 'close'];
foreach ($steps as $status => $transitions) {
    foreach ($actions as $action) {
        $next = PlacementStatus::from($status)->after($action)?->value;
        expect($next === ($transitions[$action] ?? null), "Unexpected placement transition for $status and $action.");
    }
}

echo "PASS managed placement stages, rescheduling, rejection and closure.\n";

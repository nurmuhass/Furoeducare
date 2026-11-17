<?php

declare(strict_types=1);

return [
    'up' => "UPDATE parent_tutoring_requests SET status = 'submitted', updated_at = updated_at WHERE submitted_at IS NOT NULL AND status = 'draft'",
    'down' => null,
];

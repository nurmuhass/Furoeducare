<?php

declare(strict_types=1);

return [
    'up' => 'ALTER TABLE teacher_profiles ADD COLUMN professional_verified_at DATETIME(6) NULL AFTER accepted_at',
    'down' => null,
];

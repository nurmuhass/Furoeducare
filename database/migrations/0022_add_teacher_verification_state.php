<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        ALTER TABLE teacher_profiles
            ADD COLUMN verification_status ENUM('incomplete','pending','verified','corrections_required','rejected','suspended') NOT NULL DEFAULT 'incomplete',
            ADD COLUMN verification_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
            ADD COLUMN teacher_suspension_at DATETIME(6) NULL,
            ADD KEY idx_teacher_verification_queue (verification_status, user_id)
        SQL,
    'down' => null,
];

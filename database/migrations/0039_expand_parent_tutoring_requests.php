<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        ALTER TABLE parent_tutoring_requests
            ADD COLUMN status ENUM('draft','submitted','under_review','matching','shortlisted','arrangement_pending','confirmed','closed') NOT NULL DEFAULT 'draft',
            ADD COLUMN lesson_details JSON NULL,
            ADD COLUMN selected_teacher_reference CHAR(23) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN confirmed_teacher_id BIGINT UNSIGNED NULL,
            ADD COLUMN legacy_directory_id BIGINT UNSIGNED NULL,
            ADD UNIQUE KEY uq_parent_tutoring_legacy_source (legacy_directory_id),
            ADD KEY idx_parent_tutoring_queue (status, id),
            ADD CONSTRAINT fk_parent_tutoring_confirmed_teacher
                FOREIGN KEY (confirmed_teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE RESTRICT
        SQL,
    'down' => null,
];

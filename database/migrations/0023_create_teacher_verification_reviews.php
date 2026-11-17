<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_verification_reviews (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_id BIGINT UNSIGNED NOT NULL,
            reviewer_id BIGINT UNSIGNED NOT NULL,
            revision BIGINT UNSIGNED NOT NULL,
            decision ENUM('pending','verified','corrections_required','rejected','suspended') NOT NULL,
            checks JSON NOT NULL,
            reason VARCHAR(1500) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_teacher_verification_revision (teacher_id, revision),
            KEY idx_teacher_verification_reviewer (reviewer_id),
            CONSTRAINT fk_teacher_verification_profile FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE CASCADE,
            CONSTRAINT fk_teacher_verification_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE teacher_verification_reviews',
];

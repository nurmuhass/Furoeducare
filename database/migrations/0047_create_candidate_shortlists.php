<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE candidate_shortlists (
            case_id BIGINT UNSIGNED NOT NULL,
            teacher_id BIGINT UNSIGNED NOT NULL,
            reference VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            added_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (case_id, teacher_id),
            UNIQUE KEY uq_candidate_shortlists_ref (case_id, reference),
            CONSTRAINT fk_candidate_shortlists_case FOREIGN KEY (case_id) REFERENCES candidate_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_candidate_shortlists_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_shortlists_actor FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE candidate_shortlists',
];

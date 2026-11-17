<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE candidate_disclosure_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            teacher_id BIGINT UNSIGNED NOT NULL,
            reference VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scope ENUM('professional','original_cv') NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            notice_version VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
            reason VARCHAR(1000) NOT NULL DEFAULT '',
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_candidate_disclosure_requests_case (case_id, id),
            KEY idx_candidate_disclosure_requests_queue (status, id),
            CONSTRAINT fk_candidate_disclosure_requests_case FOREIGN KEY (case_id) REFERENCES candidate_requests (id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_disclosure_requests_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_disclosure_requests_staff FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE candidate_disclosure_requests',
];

<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE candidate_privacy_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            right_type ENUM('access','correction','erasure','restriction','objection','withdraw_consent') NOT NULL,
            details VARCHAR(1500) NOT NULL,
            status ENUM('submitted','resolved') NOT NULL DEFAULT 'submitted',
            staff_note VARCHAR(1000) NOT NULL DEFAULT '',
            closed_by BIGINT UNSIGNED NULL,
            closed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_candidate_privacy_owner (account_id, id),
            KEY idx_candidate_privacy_queue (status, id),
            CONSTRAINT fk_candidate_privacy_owner FOREIGN KEY (account_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_privacy_staff FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE candidate_privacy_requests',
];

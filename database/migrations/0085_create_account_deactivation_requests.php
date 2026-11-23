<?php
declare(strict_types=1);
return [
    'up' => <<<'SQL'
        CREATE TABLE account_deactivation_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            privacy_request_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            status ENUM('pending','cancelled','reviewed') NOT NULL DEFAULT 'pending',
            pending_user_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN user_id ELSE NULL END) STORED,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_account_deactivation_pending (pending_user_id),
            UNIQUE KEY uq_account_deactivation_privacy (privacy_request_id),
            KEY idx_account_deactivation_owner (user_id, id),
            CONSTRAINT fk_account_deactivation_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_account_deactivation_privacy FOREIGN KEY (privacy_request_id) REFERENCES candidate_privacy_requests (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE account_deactivation_requests',
];

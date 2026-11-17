<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE parent_tutoring_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NOT NULL,
            subject VARCHAR(120) NOT NULL,
            requirements VARCHAR(2000) NOT NULL,
            submitted_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_parent_tutoring_requests_owner (parent_id, id),
            CONSTRAINT fk_parent_tutoring_requests_profile
                FOREIGN KEY (parent_id) REFERENCES parent_profiles (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => null,
];

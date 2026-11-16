<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
            CREATE TABLE school_onboarding (
                user_id BIGINT UNSIGNED NOT NULL,
                school_name VARCHAR(160) NOT NULL,
                approved_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (user_id),
                CONSTRAINT fk_school_onboarding_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL,
    'down' => null,
];

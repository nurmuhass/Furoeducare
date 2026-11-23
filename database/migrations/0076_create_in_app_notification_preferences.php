<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE in_app_notification_preferences (
            user_id BIGINT UNSIGNED NOT NULL,
            account_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            recruitment_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            messages_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            tutoring_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            placements_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            training_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (user_id),
            CONSTRAINT fk_in_app_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE in_app_notification_preferences',
];

<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_profiles (
            user_id BIGINT UNSIGNED NOT NULL,
            category ENUM('teacher','nysc') NOT NULL,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            phone VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            terms_version VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            privacy_version VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            accepted_at DATETIME(6) NOT NULL,
            published_at DATETIME(6) NULL,
            sections JSON NOT NULL DEFAULT (JSON_OBJECT()),
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (user_id),
            CONSTRAINT fk_teacher_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => null,
];

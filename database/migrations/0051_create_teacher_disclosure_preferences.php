<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_disclosure_preferences (
            teacher_id BIGINT UNSIGNED NOT NULL,
            paused_at DATETIME(6) NULL,
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (teacher_id),
            CONSTRAINT fk_teacher_disclosure_preferences_profile FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE teacher_disclosure_preferences',
];

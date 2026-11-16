<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_experiences (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_id BIGINT UNSIGNED NOT NULL,
            position_title VARCHAR(160) NOT NULL,
            organisation VARCHAR(160) NOT NULL,
            started_year SMALLINT UNSIGNED NULL,
            ended_year SMALLINT UNSIGNED NULL,
            description VARCHAR(1000) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_teacher_experiences_teacher (teacher_id, id),
            CONSTRAINT fk_teacher_experiences_profile FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE teacher_experiences',
];

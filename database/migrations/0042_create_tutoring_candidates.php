<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE tutoring_candidates (
            request_id BIGINT UNSIGNED NOT NULL,
            teacher_id BIGINT UNSIGNED NOT NULL,
            teacher_reference CHAR(23) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            title VARCHAR(120) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (request_id, teacher_id),
            CONSTRAINT fk_tutoring_candidates_request FOREIGN KEY (request_id) REFERENCES parent_tutoring_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_tutoring_candidates_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE tutoring_candidates',
];

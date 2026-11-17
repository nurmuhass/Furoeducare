<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE saved_jobs (
            teacher_id BIGINT UNSIGNED NOT NULL,
            vacancy_id BIGINT UNSIGNED NOT NULL,
            saved_title VARCHAR(140) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (teacher_id, vacancy_id),
            KEY idx_saved_jobs_vacancy (vacancy_id),
            CONSTRAINT fk_saved_jobs_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_saved_jobs_vacancy FOREIGN KEY (vacancy_id) REFERENCES vacancies (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE saved_jobs',
];

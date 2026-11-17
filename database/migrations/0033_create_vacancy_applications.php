<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE vacancy_applications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vacancy_id BIGINT UNSIGNED NOT NULL,
            teacher_id BIGINT UNSIGNED NOT NULL,
            status ENUM('submitted','under_review','shortlisted','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
            vacancy_revision INT UNSIGNED NOT NULL,
            job_title VARCHAR(140) NOT NULL,
            job_snapshot JSON NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_vacancy_application_teacher (vacancy_id, teacher_id),
            KEY idx_vacancy_applications_teacher (teacher_id, id),
            KEY idx_vacancy_applications_vacancy (vacancy_id, id),
            CONSTRAINT fk_vacancy_applications_vacancy FOREIGN KEY (vacancy_id) REFERENCES vacancies (id) ON DELETE RESTRICT,
            CONSTRAINT fk_vacancy_applications_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE vacancy_applications',
];

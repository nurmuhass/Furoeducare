<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE vacancies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            status ENUM('draft','submitted','under_review','approved','rejected','published','closed') NOT NULL DEFAULT 'draft',
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            content JSON NOT NULL,
            application_deadline DATE NULL,
            published_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_vacancies_owner (school_id, id),
            KEY idx_vacancies_queue (status, id),
            KEY idx_vacancies_deadline (status, application_deadline, id),
            CONSTRAINT fk_vacancies_school FOREIGN KEY (school_id) REFERENCES school_onboarding (user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE vacancies',
];

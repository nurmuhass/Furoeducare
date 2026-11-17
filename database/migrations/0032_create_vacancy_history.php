<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE vacancy_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vacancy_id BIGINT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            from_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
            to_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision INT UNSIGNED NOT NULL,
            note VARCHAR(1000) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_vacancy_history_revision (vacancy_id, revision),
            CONSTRAINT fk_vacancy_history_vacancy FOREIGN KEY (vacancy_id) REFERENCES vacancies (id) ON DELETE RESTRICT,
            CONSTRAINT fk_vacancy_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE vacancy_history',
];

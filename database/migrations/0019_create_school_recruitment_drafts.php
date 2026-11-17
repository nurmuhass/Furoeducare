<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE school_recruitment_drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            requirements VARCHAR(4000) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_school_recruitment_drafts_school (school_id, id),
            CONSTRAINT fk_school_recruitment_drafts_school FOREIGN KEY (school_id) REFERENCES school_onboarding (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE school_recruitment_drafts',
];

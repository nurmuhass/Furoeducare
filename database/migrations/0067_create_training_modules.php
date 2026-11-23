<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE training_modules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            category VARCHAR(120) NOT NULL,
            objectives TEXT NOT NULL,
            target_participants VARCHAR(300) NOT NULL,
            duration VARCHAR(120) NOT NULL,
            delivery_format ENUM('on_site','online','hybrid') NOT NULL,
            prerequisites TEXT NOT NULL,
            materials_metadata TEXT NOT NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            published_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_training_modules_catalogue (status, id),
            KEY idx_training_modules_category (status, category, id),
            CONSTRAINT fk_training_modules_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE training_modules',
];

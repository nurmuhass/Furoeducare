<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE training_package_modules (
            package_id BIGINT UNSIGNED NOT NULL,
            module_id BIGINT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (package_id, module_id),
            UNIQUE KEY uq_training_package_modules_position (package_id, position),
            CONSTRAINT fk_training_package_modules_package FOREIGN KEY (package_id) REFERENCES training_packages (id) ON DELETE CASCADE,
            CONSTRAINT fk_training_package_modules_module FOREIGN KEY (module_id) REFERENCES training_modules (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE training_package_modules',
];

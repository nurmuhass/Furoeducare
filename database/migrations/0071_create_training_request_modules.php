<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE training_request_modules (
            request_id BIGINT UNSIGNED NOT NULL,
            module_id BIGINT UNSIGNED NOT NULL,
            module_revision INT UNSIGNED NOT NULL,
            title_snapshot VARCHAR(200) NOT NULL,
            snapshot JSON NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (request_id, module_id),
            CONSTRAINT fk_training_request_modules_request FOREIGN KEY (request_id) REFERENCES training_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_training_request_modules_module FOREIGN KEY (module_id) REFERENCES training_modules (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE training_request_modules',
];

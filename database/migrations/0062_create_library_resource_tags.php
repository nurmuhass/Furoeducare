<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE library_resource_tags (
            resource_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('category','subject','level') NOT NULL,
            label VARCHAR(120) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (resource_id, kind, label),
            KEY idx_library_resource_tags_filter (kind, label, resource_id),
            CONSTRAINT fk_library_resource_tags_resource FOREIGN KEY (resource_id) REFERENCES library_resources (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE library_resource_tags',
];

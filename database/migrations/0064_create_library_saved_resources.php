<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE library_saved_resources (
            user_id BIGINT UNSIGNED NOT NULL,
            resource_id BIGINT UNSIGNED NOT NULL,
            title_snapshot VARCHAR(200) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (user_id, resource_id),
            CONSTRAINT fk_library_saved_resources_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_library_saved_resources_resource FOREIGN KEY (resource_id) REFERENCES library_resources (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE library_saved_resources',
];

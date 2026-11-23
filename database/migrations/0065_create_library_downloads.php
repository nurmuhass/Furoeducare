<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE library_downloads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            resource_id BIGINT UNSIGNED NOT NULL,
            resource_revision INT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            title_snapshot VARCHAR(200) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_library_downloads_resource (resource_id, id),
            KEY idx_library_downloads_owner (user_id, id),
            CONSTRAINT fk_library_downloads_resource FOREIGN KEY (resource_id) REFERENCES library_resources (id) ON DELETE RESTRICT,
            CONSTRAINT fk_library_downloads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE library_downloads',
];

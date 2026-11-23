<?php
declare(strict_types=1);
return [
    'up' => <<<'SQL'
        CREATE TABLE staff_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name VARCHAR(100) NOT NULL,
            built_in BOOLEAN NOT NULL DEFAULT FALSE,
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id), UNIQUE KEY uq_staff_roles_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE staff_roles',
];

<?php
declare(strict_types=1);
return [
    'up' => <<<'SQL'
        CREATE TABLE staff_role_permissions (
            role_id BIGINT UNSIGNED NOT NULL,
            permission VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (role_id, permission),
            CONSTRAINT fk_staff_role_permissions_role FOREIGN KEY (role_id) REFERENCES staff_roles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE staff_role_permissions',
];

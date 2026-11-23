<?php
declare(strict_types=1);
return [
    'up' => <<<'SQL'
        CREATE TABLE staff_user_roles (
            user_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            granted_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (user_id, role_id),
            CONSTRAINT fk_staff_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_staff_user_roles_role FOREIGN KEY (role_id) REFERENCES staff_roles (id) ON DELETE RESTRICT,
            CONSTRAINT fk_staff_user_roles_granter FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE staff_user_roles',
];

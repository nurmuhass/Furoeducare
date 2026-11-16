<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
            CREATE TABLE users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(254) COLLATE utf8mb4_0900_as_ci NOT NULL,
                password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                email_verified_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                UNIQUE KEY uq_users_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL,
    'down' => 'DROP TABLE users',
];

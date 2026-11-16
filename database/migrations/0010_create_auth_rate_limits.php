<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
            CREATE TABLE auth_rate_limits (
                bucket BINARY(32) NOT NULL,
                attempts SMALLINT UNSIGNED NOT NULL,
                window_started_at DATETIME(6) NOT NULL,
                PRIMARY KEY (bucket),
                KEY idx_auth_rate_limits_window (window_started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL,
    'down' => 'DROP TABLE auth_rate_limits',
];

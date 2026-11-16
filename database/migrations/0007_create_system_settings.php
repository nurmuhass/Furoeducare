<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
            CREATE TABLE system_settings (
                setting_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                setting_value JSON NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL,
    'down' => 'DROP TABLE system_settings',
];

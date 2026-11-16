<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
            CREATE TABLE audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_user_id BIGINT UNSIGNED NULL,
                action VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                context JSON NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY idx_audit_logs_actor_created (actor_user_id, created_at),
                KEY idx_audit_logs_created (created_at),
                CONSTRAINT fk_audit_logs_actor_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL,
    'down' => 'DROP TABLE audit_logs',
];

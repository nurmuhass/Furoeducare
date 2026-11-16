<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
            CREATE TABLE email_verification_tokens (
                token_hash BINARY(32) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                email_at_issue VARCHAR(254) COLLATE utf8mb4_0900_as_ci NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                expires_at DATETIME(6) NOT NULL,
                consumed_at DATETIME(6) NULL,
                PRIMARY KEY (token_hash),
                KEY idx_email_verification_tokens_user_expires (user_id, expires_at),
                KEY idx_email_verification_tokens_expires (expires_at),
                CONSTRAINT fk_email_verification_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL,
    'down' => 'DROP TABLE email_verification_tokens',
];

<?php
declare(strict_types=1);
return [
    'up' => <<<'SQL'
        CREATE TABLE account_email_changes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            old_email VARCHAR(254) NOT NULL,
            new_email VARCHAR(254) NOT NULL,
            auth_version INT UNSIGNED NOT NULL,
            token_hash BINARY(32) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            consumed_at DATETIME(6) NULL,
            cancelled_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_account_email_changes_token (token_hash),
            KEY idx_account_email_changes_owner (user_id, id),
            KEY idx_account_email_changes_expiry (expires_at),
            CONSTRAINT fk_account_email_changes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE account_email_changes',
];

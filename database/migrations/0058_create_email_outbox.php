<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE email_outbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            recipient_email VARCHAR(254) NOT NULL,
            template VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload JSON NOT NULL,
            secret_payload TEXT NULL,
            token_hash BINARY(32) NULL,
            expires_at DATETIME(6) NULL,
            payload_hash BINARY(32) NOT NULL,
            idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status ENUM('pending','processing','accepted','failed','suppressed','uncertain') NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            locked_until DATETIME(6) NULL,
            lock_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            provider_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            provider_id VARCHAR(255) NULL,
            last_error_code VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
            accepted_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_email_outbox_event (idempotency_key),
            KEY idx_email_outbox_pending (status, available_at, id),
            KEY idx_email_outbox_lease (status, locked_until),
            KEY idx_email_outbox_user (user_id, id),
            CONSTRAINT fk_email_outbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE email_outbox',
];

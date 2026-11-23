<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE email_delivery_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            outbox_id BIGINT UNSIGNED NOT NULL,
            attempt INT UNSIGNED NOT NULL,
            status ENUM('processing','accepted','retried','failed','suppressed','uncertain') NOT NULL DEFAULT 'processing',
            error_code VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
            provider_id VARCHAR(255) NULL,
            started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            finished_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_email_delivery_attempt (outbox_id, attempt),
            CONSTRAINT fk_email_delivery_attempt_outbox FOREIGN KEY (outbox_id) REFERENCES email_outbox (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE email_delivery_attempts',
];

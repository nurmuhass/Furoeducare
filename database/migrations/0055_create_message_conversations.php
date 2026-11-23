<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE message_conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_id BIGINT UNSIGNED NOT NULL,
            owner_role ENUM('teacher','school','parent') NOT NULL,
            subject VARCHAR(160) NOT NULL,
            context_type ENUM('support','recruitment','vacancy','tutoring','candidate_information','placement') NOT NULL,
            context_id BIGINT UNSIGNED NULL,
            status ENUM('open','pending','closed') NOT NULL DEFAULT 'open',
            assigned_admin_id BIGINT UNSIGNED NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            last_message_id BIGINT UNSIGNED NULL,
            client_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            retention_hold BOOLEAN NOT NULL DEFAULT FALSE,
            closed_at DATETIME(6) NULL,
            purged_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_message_conversations_retry (owner_id, client_token),
            KEY idx_message_conversations_owner (owner_id, id),
            KEY idx_message_conversations_status (status, id),
            KEY idx_message_conversations_assigned (assigned_admin_id, status, id),
            KEY idx_message_conversations_context (context_type, context_id, id),
            KEY idx_message_conversations_retention (status, retention_hold, purged_at, closed_at),
            CONSTRAINT chk_message_conversations_context CHECK (
                (context_type = 'support' AND context_id IS NULL) OR (context_type <> 'support' AND context_id IS NOT NULL)
            ),
            CONSTRAINT fk_message_conversations_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_message_conversations_admin FOREIGN KEY (assigned_admin_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE message_conversations',
];

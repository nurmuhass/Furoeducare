<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE conversation_reads (
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (conversation_id, user_id),
            CONSTRAINT fk_conversation_reads_thread FOREIGN KEY (conversation_id) REFERENCES message_conversations (id) ON DELETE CASCADE,
            CONSTRAINT fk_conversation_reads_account FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE conversation_reads',
];

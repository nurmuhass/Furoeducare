<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE conversation_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            is_staff BOOLEAN NOT NULL,
            body TEXT NOT NULL,
            client_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_conversation_messages_retry (conversation_id, sender_id, client_token),
            KEY idx_conversation_messages_thread (conversation_id, id),
            CONSTRAINT fk_conversation_messages_thread FOREIGN KEY (conversation_id) REFERENCES message_conversations (id) ON DELETE RESTRICT,
            CONSTRAINT fk_conversation_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE conversation_messages',
];

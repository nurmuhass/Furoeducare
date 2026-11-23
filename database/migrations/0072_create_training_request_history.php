<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE training_request_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            action VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            from_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
            to_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            note TEXT NOT NULL,
            details JSON NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_training_request_history_request (request_id, id),
            CONSTRAINT fk_training_request_history_request FOREIGN KEY (request_id) REFERENCES training_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_training_request_history_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE training_request_history',
];

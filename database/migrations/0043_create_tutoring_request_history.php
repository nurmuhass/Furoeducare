<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE tutoring_request_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            from_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            to_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            note VARCHAR(1000) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_tutoring_request_history_request (request_id, id),
            CONSTRAINT fk_tutoring_history_request FOREIGN KEY (request_id) REFERENCES parent_tutoring_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_tutoring_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE tutoring_request_history',
];

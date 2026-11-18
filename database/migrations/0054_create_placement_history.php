<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE placement_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            placement_id BIGINT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            from_status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
            to_status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            action VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            note VARCHAR(1000) NOT NULL,
            context JSON NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_placement_history_placement (placement_id, id),
            CONSTRAINT fk_placement_history_placement FOREIGN KEY (placement_id) REFERENCES placements (id) ON DELETE RESTRICT,
            CONSTRAINT fk_placement_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE placement_history',
];

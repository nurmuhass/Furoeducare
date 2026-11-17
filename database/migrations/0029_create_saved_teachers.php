<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE saved_teachers (
            user_id BIGINT UNSIGNED NOT NULL,
            listing_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (user_id, listing_id),
            CONSTRAINT fk_saved_teachers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_saved_teachers_listing FOREIGN KEY (listing_id) REFERENCES teacher_listings (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE saved_teachers',
];

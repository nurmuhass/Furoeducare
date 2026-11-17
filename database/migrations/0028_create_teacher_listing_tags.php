<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_listing_tags (
            listing_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('subject','level') NOT NULL,
            label VARCHAR(60) NOT NULL,
            PRIMARY KEY (listing_id, kind, label),
            KEY idx_teacher_listing_tags_filter (kind, label, listing_id),
            CONSTRAINT fk_teacher_listing_tags_listing FOREIGN KEY (listing_id) REFERENCES teacher_listings (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE teacher_listing_tags',
];

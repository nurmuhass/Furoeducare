<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_listing_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requester_id BIGINT UNSIGNED NOT NULL,
            listing_id BIGINT UNSIGNED NOT NULL,
            requester_role ENUM('parent','school') NOT NULL,
            notes VARCHAR(1500) NOT NULL,
            submitted_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_teacher_listing_requests_owner (requester_id, listing_id),
            KEY idx_teacher_listing_requests_owner (requester_id, id),
            KEY idx_teacher_listing_requests_submitted (submitted_at, id),
            CONSTRAINT fk_teacher_listing_requests_user FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_teacher_listing_requests_listing FOREIGN KEY (listing_id) REFERENCES teacher_listings (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE teacher_listing_requests',
];

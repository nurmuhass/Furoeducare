<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE candidate_disclosure_downloads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            grant_id BIGINT UNSIGNED NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            document_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_candidate_disclosure_downloads_grant (grant_id, id),
            CONSTRAINT fk_candidate_disclosure_downloads_grant FOREIGN KEY (grant_id) REFERENCES candidate_disclosure_grants (id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_disclosure_downloads_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE candidate_disclosure_downloads',
];

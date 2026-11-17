<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE school_reviews (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            reviewer_id BIGINT UNSIGNED NOT NULL,
            decision ENUM('approved','rejected','corrections_required','suspended') NOT NULL,
            reason VARCHAR(1500) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_school_reviews_school (school_id, id),
            KEY idx_school_reviews_reviewer (reviewer_id, id),
            CONSTRAINT fk_school_reviews_school FOREIGN KEY (school_id) REFERENCES school_onboarding (user_id) ON DELETE CASCADE,
            CONSTRAINT fk_school_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => null,
];

<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_document_reviews (
            id BIGINT UNSIGNED NOT NULL,
            document_id BIGINT UNSIGNED NULL,
            teacher_id BIGINT UNSIGNED NOT NULL,
            reviewer_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('cv','qualification','certification','other') NOT NULL,
            decision ENUM('accepted','rejected') NOT NULL,
            reason VARCHAR(1500) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_teacher_document_review_document (document_id),
            KEY idx_teacher_document_review_teacher (teacher_id, id),
            KEY idx_teacher_document_reviewer (reviewer_id),
            CONSTRAINT fk_teacher_document_review_document FOREIGN KEY (document_id) REFERENCES teacher_documents (id) ON DELETE SET NULL,
            CONSTRAINT fk_teacher_document_review_profile FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE CASCADE,
            CONSTRAINT fk_teacher_document_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE teacher_document_reviews',
];

<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('cv','qualification','certification','other') NOT NULL,
            storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            display_name VARCHAR(180) NOT NULL,
            media_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            bytes INT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            delete_requested_at DATETIME(6) NULL,
            cv_teacher_id BIGINT UNSIGNED GENERATED ALWAYS AS (
                CASE WHEN kind = 'cv' AND delete_requested_at IS NULL THEN teacher_id END
            ) STORED,
            PRIMARY KEY (id),
            UNIQUE KEY uq_teacher_documents_storage_key (storage_key),
            UNIQUE KEY uq_teacher_documents_cv (cv_teacher_id),
            KEY idx_teacher_documents_teacher (teacher_id, delete_requested_at, id),
            CONSTRAINT fk_teacher_documents_profile FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => null,
];

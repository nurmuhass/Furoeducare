<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_cv_extractions (
            document_id BIGINT UNSIGNED NOT NULL,
            status ENUM('queued', 'processing', 'ready', 'failed', 'applied') NOT NULL DEFAULT 'queued',
            draft JSON NULL,
            failure_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            started_at DATETIME(6) NULL,
            expires_at DATETIME(6) NULL,
            applied_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (document_id),
            KEY idx_teacher_cv_extractions_queue (status, document_id),
            KEY idx_teacher_cv_extractions_expiry (status, expires_at),
            KEY idx_teacher_cv_extractions_started (status, started_at),
            CONSTRAINT fk_teacher_cv_extractions_document
                FOREIGN KEY (document_id) REFERENCES teacher_documents (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE teacher_cv_extractions',
];

<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE training_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference CHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            school_id BIGINT UNSIGNED NOT NULL,
            package_id BIGINT UNSIGNED NULL,
            package_title VARCHAR(200) NULL,
            participants SMALLINT UNSIGNED NOT NULL,
            location VARCHAR(500) NOT NULL,
            preferred_dates JSON NOT NULL,
            requirements TEXT NOT NULL,
            client_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status ENUM('submitted','under_review','discussion','scheduled','completed','cancelled') NOT NULL DEFAULT 'submitted',
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            assigned_admin_id BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            approval_notes TEXT NULL,
            scheduled_start DATETIME(6) NULL,
            scheduled_end DATETIME(6) NULL,
            session_format ENUM('on_site','online','hybrid') NULL,
            session_location VARCHAR(500) NULL,
            outcome TEXT NULL,
            completed_at DATETIME(6) NULL,
            cancelled_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_training_requests_reference (reference),
            UNIQUE KEY uq_training_requests_token (school_id, client_token),
            KEY idx_training_requests_owner (school_id, id),
            KEY idx_training_requests_status (status, id),
            KEY idx_training_requests_assignment (assigned_admin_id, status, id),
            CONSTRAINT fk_training_requests_school FOREIGN KEY (school_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_training_requests_package FOREIGN KEY (package_id) REFERENCES training_packages (id) ON DELETE RESTRICT,
            CONSTRAINT fk_training_requests_assignee FOREIGN KEY (assigned_admin_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_training_requests_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE training_requests',
];

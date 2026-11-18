<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE placements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind ENUM('employment','tutoring') NOT NULL,
            application_id BIGINT UNSIGNED NULL,
            tutoring_id BIGINT UNSIGNED NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            teacher_id BIGINT UNSIGNED NOT NULL,
            teacher_reference VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            mode ENUM('home','online') NULL,
            status ENUM('screening','shortlisted','discussion_scheduled','availability_confirmed','selected',
                'offer_pending','confirmed','rejected','closed') NOT NULL DEFAULT 'screening',
            assigned_admin_id BIGINT UNSIGNED NOT NULL,
            shortlisted_at DATETIME(6) NULL,
            scheduled_at DATETIME NULL,
            offer_summary VARCHAR(1000) NULL,
            starts_on DATE NULL,
            safeguarding_reference VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL,
            safeguarding_revision INT UNSIGNED NULL,
            safeguarding_checked_by BIGINT UNSIGNED NULL,
            safeguarding_checked_at DATETIME(6) NULL,
            professional_revision INT UNSIGNED NULL,
            confirmed_at DATETIME(6) NULL,
            closed_at DATETIME(6) NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            active_tutoring_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'rejected', NULL, tutoring_id)) STORED,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_placements_application (application_id),
            UNIQUE KEY uq_placements_active_tutoring (active_tutoring_id),
            KEY idx_placements_tutoring (tutoring_id, id),
            KEY idx_placements_requester (requester_id, id),
            KEY idx_placements_teacher (teacher_id, id),
            CONSTRAINT chk_placements_source CHECK (
                (kind = 'employment' AND application_id IS NOT NULL AND tutoring_id IS NULL AND mode IS NULL)
                OR (kind = 'tutoring' AND application_id IS NULL AND tutoring_id IS NOT NULL AND mode IS NOT NULL)
            ),
            CONSTRAINT fk_placements_application FOREIGN KEY (application_id) REFERENCES vacancy_applications (id) ON DELETE RESTRICT,
            CONSTRAINT fk_placements_tutoring FOREIGN KEY (tutoring_id) REFERENCES parent_tutoring_requests (id) ON DELETE RESTRICT,
            CONSTRAINT fk_placements_requester FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_placements_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE RESTRICT,
            CONSTRAINT fk_placements_admin FOREIGN KEY (assigned_admin_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_placements_safeguarding_staff FOREIGN KEY (safeguarding_checked_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE placements',
];

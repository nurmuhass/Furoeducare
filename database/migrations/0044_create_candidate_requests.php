<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE candidate_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requester_id BIGINT UNSIGNED NOT NULL,
            requester_role ENUM('school','parent') NOT NULL,
            category ENUM('recruitment','tutoring') NOT NULL,
            selected_teacher_id BIGINT UNSIGNED NULL,
            selected_reference VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL,
            vacancy_id BIGINT UNSIGNED NULL,
            tutoring_id BIGINT UNSIGNED NULL,
            purpose VARCHAR(250) NOT NULL,
            requirements VARCHAR(2000) NOT NULL,
            status ENUM('draft','submitted','under_review','shortlisted','closed') NOT NULL DEFAULT 'submitted',
            assigned_admin_id BIGINT UNSIGNED NULL,
            shortlisted_at DATETIME(6) NULL,
            legacy_directory_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_candidate_requests_tutoring (tutoring_id),
            UNIQUE KEY uq_candidate_requests_legacy (legacy_directory_id),
            KEY idx_candidate_requests_owner (requester_id, requester_role, id),
            KEY idx_candidate_requests_queue (status, id),
            CONSTRAINT fk_candidate_requests_owner FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_requests_teacher FOREIGN KEY (selected_teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE SET NULL,
            CONSTRAINT fk_candidate_requests_vacancy FOREIGN KEY (vacancy_id) REFERENCES vacancies (id) ON DELETE SET NULL,
            CONSTRAINT fk_candidate_requests_tutoring FOREIGN KEY (tutoring_id) REFERENCES parent_tutoring_requests (id) ON DELETE SET NULL,
            CONSTRAINT fk_candidate_requests_admin FOREIGN KEY (assigned_admin_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE candidate_requests',
];

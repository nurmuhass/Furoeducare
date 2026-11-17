<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE candidate_disclosure_grants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            disclosure_id BIGINT UNSIGNED NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            teacher_id BIGINT UNSIGNED NOT NULL,
            scope ENUM('professional','original_cv') NOT NULL,
            document_id BIGINT UNSIGNED NULL,
            profile_revision BIGINT UNSIGNED NULL,
            profile_data JSON NULL,
            lawful_basis ENUM('specific_consent') NOT NULL,
            evidence_reference VARCHAR(160) NOT NULL,
            policy_version VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            expires_at DATETIME(6) NOT NULL,
            revoked_at DATETIME(6) NULL,
            revoked_by BIGINT UNSIGNED NULL,
            revocation_reason VARCHAR(1000) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY uq_candidate_disclosure_grants_request (disclosure_id),
            KEY idx_candidate_disclosure_grants_recipient (recipient_id, id),
            KEY idx_candidate_disclosure_grants_teacher (teacher_id, revoked_at, expires_at),
            CONSTRAINT chk_candidate_disclosure_scope CHECK
                ((scope = 'professional' AND profile_data IS NOT NULL AND profile_revision IS NOT NULL AND document_id IS NULL)
                OR (scope = 'original_cv' AND document_id IS NOT NULL AND profile_data IS NULL AND profile_revision IS NULL)),
            CONSTRAINT fk_candidate_disclosure_grants_request FOREIGN KEY (disclosure_id) REFERENCES candidate_disclosure_requests (id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_disclosure_grants_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_disclosure_grants_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE RESTRICT,
            CONSTRAINT fk_candidate_disclosure_grants_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_candidate_disclosure_grants_revoker FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE candidate_disclosure_grants',
];

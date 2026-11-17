<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        ALTER TABLE school_onboarding
            ADD COLUMN category VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN state_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN city VARCHAR(100) NULL,
            ADD COLUMN address VARCHAR(500) NULL,
            ADD COLUMN contact_email VARCHAR(254) NULL,
            ADD COLUMN contact_phone VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN representative_name VARCHAR(120) NULL,
            ADD COLUMN representative_position VARCHAR(100) NULL,
            ADD COLUMN institution_name_key VARCHAR(160) NULL,
            ADD COLUMN institution_city_key VARCHAR(100) NULL,
            ADD COLUMN terms_version VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN privacy_version VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN accepted_at DATETIME(6) NULL,
            ADD COLUMN review_status ENUM('pending','approved','rejected','corrections_required','suspended') NULL,
            ADD COLUMN school_suspension_at DATETIME(6) NULL,
            ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1,
            ADD UNIQUE KEY uq_school_onboarding_institution (state_code, institution_city_key, institution_name_key)
        SQL,
    'down' => null,
];

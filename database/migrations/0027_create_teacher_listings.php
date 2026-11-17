<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE teacher_listings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_id BIGINT UNSIGNED NOT NULL,
            reference CHAR(23) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            title VARCHAR(120) NOT NULL,
            bio VARCHAR(600) NOT NULL,
            qualification VARCHAR(160) NOT NULL,
            experience TINYINT UNSIGNED NOT NULL,
            state_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            city VARCHAR(80) NOT NULL,
            availability_mask TINYINT UNSIGNED NOT NULL,
            professional_name VARCHAR(100) NULL,
            photograph MEDIUMBLOB NULL,
            approval_basis VARCHAR(1000) NOT NULL,
            approved_at DATETIME(6) NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_teacher_listings_teacher (teacher_id),
            UNIQUE KEY uq_teacher_listings_reference (reference),
            KEY idx_teacher_listings_state_city (state_code, city, reference),
            KEY idx_teacher_listings_city (city, reference),
            KEY idx_teacher_listings_qualification (qualification, reference),
            KEY idx_teacher_listings_experience (experience, reference),
            CONSTRAINT chk_teacher_listings_experience CHECK (experience <= 70),
            CONSTRAINT chk_teacher_listings_availability CHECK (availability_mask BETWEEN 1 AND 15),
            CONSTRAINT fk_teacher_listings_profile FOREIGN KEY (teacher_id) REFERENCES teacher_profiles (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE teacher_listings',
];

<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE library_resources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference CHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            title VARCHAR(200) NOT NULL,
            author VARCHAR(160) NOT NULL DEFAULT '',
            description TEXT NOT NULL,
            publisher VARCHAR(160) NOT NULL DEFAULT '',
            publication_year SMALLINT UNSIGNED NULL,
            edition VARCHAR(80) NOT NULL DEFAULT '',
            isbn VARCHAR(32) NOT NULL DEFAULT '',
            access ENUM('free','restricted','future_paid') NOT NULL DEFAULT 'free',
            status ENUM('draft','published','unpublished','archived','removed') NOT NULL DEFAULT 'draft',
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            licence VARCHAR(160) NOT NULL DEFAULT '',
            rights_reference VARCHAR(300) NOT NULL DEFAULT '',
            rights_expires_on DATE NULL,
            rights_confirmed_by BIGINT UNSIGNED NULL,
            rights_confirmed_at DATETIME(6) NULL,
            material_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            material_media_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
            material_extension ENUM('pdf','epub') NULL,
            material_bytes INT UNSIGNED NULL,
            material_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            cover_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            cover_media_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
            cover_bytes INT UNSIGNED NULL,
            cover_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            created_by BIGINT UNSIGNED NULL,
            published_at DATETIME(6) NULL,
            removed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_library_resources_reference (reference),
            KEY idx_library_resources_catalogue (status, id),
            KEY idx_library_resources_access (status, access, id),
            KEY idx_library_resources_rights (status, rights_expires_on, id),
            CONSTRAINT fk_library_resources_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_library_resources_rights_staff FOREIGN KEY (rights_confirmed_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE library_resources',
];

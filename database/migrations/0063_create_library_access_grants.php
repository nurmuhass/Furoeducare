<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE library_access_grants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            resource_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            resource_revision INT UNSIGNED NOT NULL,
            granted_by BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            revoked_at DATETIME(6) NULL,
            revoked_by BIGINT UNSIGNED NULL,
            revocation_reason VARCHAR(500) NULL,
            active_user_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(revoked_at IS NULL, user_id, NULL)) STORED,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_library_access_grants_current (resource_id, active_user_id),
            KEY idx_library_access_grants_owner (user_id, resource_id, expires_at),
            CONSTRAINT fk_library_access_grants_resource FOREIGN KEY (resource_id) REFERENCES library_resources (id) ON DELETE RESTRICT,
            CONSTRAINT fk_library_access_grants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_library_access_grants_staff FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_library_access_grants_revoker FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE library_access_grants',
];

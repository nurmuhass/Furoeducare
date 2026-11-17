<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        CREATE TABLE verification_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            title VARCHAR(150) NOT NULL,
            message VARCHAR(1500) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY idx_verification_notification_recipient (user_id, id),
            CONSTRAINT fk_verification_notification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL,
    'down' => 'DROP TABLE verification_notifications',
];

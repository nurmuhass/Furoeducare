<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        ALTER TABLE verification_notifications
            ADD COLUMN category VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'account',
            ADD COLUMN path VARCHAR(500) NOT NULL DEFAULT '/dashboard',
            ADD COLUMN event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN in_app_visible BOOLEAN NOT NULL DEFAULT TRUE,
            ADD COLUMN read_at DATETIME(6) NULL,
            ADD UNIQUE KEY uq_notification_event (user_id, event_key),
            ADD KEY idx_notification_unread (user_id, in_app_visible, read_at, id),
            ADD KEY idx_notification_category (user_id, in_app_visible, category, id)
        SQL,
    'down' => <<<'SQL'
        ALTER TABLE verification_notifications
            DROP INDEX idx_notification_category,
            DROP INDEX idx_notification_unread,
            DROP INDEX uq_notification_event,
            DROP COLUMN read_at,
            DROP COLUMN in_app_visible,
            DROP COLUMN payload_hash,
            DROP COLUMN event_key,
            DROP COLUMN path,
            DROP COLUMN category
        SQL,
];

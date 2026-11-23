<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        UPDATE verification_notifications SET
            event_key = SHA2(CONCAT('legacy:', id), 256),
            payload_hash = SHA2(CONCAT(event, ':', title, ':', message), 256),
            category = CASE
                WHEN event LIKE 'messaging.%' THEN 'messages'
                WHEN event LIKE 'training.%' THEN 'training'
                WHEN event LIKE 'tutoring.%' THEN 'tutoring'
                WHEN event = 'placement.discussion_scheduled' THEN 'recruitment'
                WHEN event LIKE 'placement.%' THEN 'placements'
                WHEN event LIKE 'disclosure.%' OR event LIKE 'vacancy.%' OR event LIKE 'application.%'
                    OR event LIKE 'candidate.%' THEN 'recruitment'
                ELSE 'account'
            END,
            path = '/dashboard'
        WHERE event_key IS NULL
        SQL,
    'down' => <<<'SQL'
        UPDATE verification_notifications SET event_key = NULL, payload_hash = NULL, category = 'account', path = '/dashboard'
        WHERE event_key = SHA2(CONCAT('legacy:', id), 256)
        SQL,
];

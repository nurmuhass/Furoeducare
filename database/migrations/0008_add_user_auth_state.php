<?php

declare(strict_types=1);

return [
    'up' => 'ALTER TABLE users ADD COLUMN suspended_at DATETIME(6) NULL, ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1',
    // Dropping the version could revive an old authentication session.
    'down' => null,
];

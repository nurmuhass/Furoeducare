<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        ALTER TABLE school_reviews ADD COLUMN checks JSON NULL AFTER decision
        SQL,
    'down' => null,
];

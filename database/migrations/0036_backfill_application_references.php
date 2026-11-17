<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        UPDATE vacancy_applications
            SET candidate_reference = CONCAT('CA-', HEX(RANDOM_BYTES(16))),
                status = IF(status = 'rejected', 'unsuccessful', status),
                updated_at = updated_at
            WHERE candidate_reference IS NULL
        SQL,
    'down' => null,
];

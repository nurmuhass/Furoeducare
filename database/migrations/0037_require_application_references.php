<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        ALTER TABLE vacancy_applications
            MODIFY COLUMN candidate_reference CHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            MODIFY COLUMN status ENUM('submitted','under_review','shortlisted','interview_invited',
                'offer_pending','successful','unsuccessful','withdrawn') NOT NULL DEFAULT 'submitted'
        SQL,
    'down' => <<<'SQL'
        ALTER TABLE vacancy_applications
            MODIFY COLUMN candidate_reference CHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL,
            MODIFY COLUMN status ENUM('submitted','under_review','shortlisted','rejected','withdrawn',
                'interview_invited','offer_pending','successful','unsuccessful') NOT NULL DEFAULT 'submitted'
        SQL,
];

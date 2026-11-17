<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        ALTER TABLE vacancy_applications
            MODIFY COLUMN status ENUM('submitted','under_review','shortlisted','rejected','withdrawn',
                'interview_invited','offer_pending','successful','unsuccessful') NOT NULL DEFAULT 'submitted',
            ADD COLUMN candidate_reference CHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD COLUMN application_statement VARCHAR(2000) NOT NULL DEFAULT '',
            ADD COLUMN profile_snapshot JSON NULL,
            ADD UNIQUE KEY uq_vacancy_applications_candidate (candidate_reference)
        SQL,
    'down' => null,
];

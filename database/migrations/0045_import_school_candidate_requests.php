<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        INSERT INTO candidate_requests (requester_id, requester_role, category, selected_teacher_id, selected_reference,
            purpose, requirements, status, legacy_directory_id, created_at, updated_at)
        SELECT r.requester_id, 'school', 'recruitment', l.teacher_id, l.reference, 'Teacher recruitment', r.notes,
            IF(r.submitted_at IS NULL, 'draft', 'submitted'), r.id, r.created_at, r.updated_at
        FROM teacher_listing_requests r JOIN teacher_listings l ON l.id = r.listing_id
        WHERE r.requester_role = 'school'
        SQL,
    'down' => null,
];

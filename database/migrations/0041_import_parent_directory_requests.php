<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        INSERT INTO parent_tutoring_requests (parent_id, subject, requirements, submitted_at, created_at, updated_at,
            status, selected_teacher_reference, legacy_directory_id)
        SELECT r.requester_id, 'Requested teacher', r.notes, r.submitted_at, r.created_at, r.updated_at,
            IF(r.submitted_at IS NULL, 'draft', 'submitted'), l.reference, r.id
        FROM teacher_listing_requests r
        JOIN parent_profiles p ON p.user_id = r.requester_id
        JOIN teacher_listings l ON l.id = r.listing_id
        WHERE r.requester_role = 'parent'
        SQL,
    'down' => null,
];

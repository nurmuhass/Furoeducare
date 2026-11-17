<?php

declare(strict_types=1);

return [
    'up' => <<<'SQL'
        INSERT INTO candidate_requests (requester_id, requester_role, category, selected_teacher_id, selected_reference,
            purpose, requirements, status, tutoring_id, created_at, updated_at)
        SELECT t.parent_id, 'parent', 'tutoring', l.teacher_id, t.selected_teacher_reference,
            'Tutoring placement', 'See the linked tutoring request.', IF(t.status = 'closed', 'closed', 'submitted'),
            t.id, t.submitted_at, t.updated_at
        FROM parent_tutoring_requests t LEFT JOIN teacher_listings l ON l.reference = t.selected_teacher_reference
        WHERE t.submitted_at IS NOT NULL
        SQL,
    'down' => null,
];

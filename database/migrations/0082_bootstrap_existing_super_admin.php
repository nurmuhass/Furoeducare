<?php
declare(strict_types=1);
return [
    'up' => <<<'SQL'
        INSERT INTO staff_user_roles (user_id, role_id)
        SELECT u.id, sr.id FROM users u JOIN staff_roles sr ON sr.slug = 'super_admin'
        WHERE u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
            AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'admin')
        ORDER BY u.id LIMIT 1
        SQL,
    'down' => "DELETE FROM staff_user_roles WHERE role_id = (SELECT id FROM staff_roles WHERE slug = 'super_admin') AND granted_by IS NULL",
];

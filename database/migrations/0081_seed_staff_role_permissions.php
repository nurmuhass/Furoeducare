<?php

declare(strict_types=1);

// A fixed migration snapshot; later permission changes require their own migration.
return [
    'up' => <<<'SQL'
INSERT INTO staff_role_permissions (role_id, permission)
SELECT id, 'overview.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'teachers.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'schools.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'parents.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'accounts.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'verification.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'verification.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'documents.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'directory.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'directory.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'vacancies.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'vacancies.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'applications.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'applications.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'tutoring.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'tutoring.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'candidates.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'candidates.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'cv_requests.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'cv_access.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'placements.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'placements.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'library.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'library.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'training.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'training.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'messages.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'messages.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'email.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'reports.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'staff.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'staff.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'audit.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'settings.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'settings.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'privacy.view' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'privacy.manage' FROM staff_roles WHERE slug = 'super_admin'
UNION ALL
SELECT id, 'overview.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'teachers.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'schools.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'parents.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'directory.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'vacancies.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'vacancies.manage' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'applications.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'applications.manage' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'tutoring.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'tutoring.manage' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'candidates.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'candidates.manage' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'cv_requests.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'placements.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'placements.manage' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'messages.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'messages.manage' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'reports.view' FROM staff_roles WHERE slug = 'recruitment_officer'
UNION ALL
SELECT id, 'overview.view' FROM staff_roles WHERE slug = 'verification_officer'
UNION ALL
SELECT id, 'teachers.view' FROM staff_roles WHERE slug = 'verification_officer'
UNION ALL
SELECT id, 'schools.view' FROM staff_roles WHERE slug = 'verification_officer'
UNION ALL
SELECT id, 'verification.view' FROM staff_roles WHERE slug = 'verification_officer'
UNION ALL
SELECT id, 'verification.manage' FROM staff_roles WHERE slug = 'verification_officer'
UNION ALL
SELECT id, 'documents.view' FROM staff_roles WHERE slug = 'verification_officer'
UNION ALL
SELECT id, 'directory.view' FROM staff_roles WHERE slug = 'verification_officer'
UNION ALL
SELECT id, 'directory.manage' FROM staff_roles WHERE slug = 'verification_officer'
UNION ALL
SELECT id, 'overview.view' FROM staff_roles WHERE slug = 'library_manager'
UNION ALL
SELECT id, 'library.view' FROM staff_roles WHERE slug = 'library_manager'
UNION ALL
SELECT id, 'library.manage' FROM staff_roles WHERE slug = 'library_manager'
UNION ALL
SELECT id, 'reports.view' FROM staff_roles WHERE slug = 'library_manager'
UNION ALL
SELECT id, 'overview.view' FROM staff_roles WHERE slug = 'training_coordinator'
UNION ALL
SELECT id, 'schools.view' FROM staff_roles WHERE slug = 'training_coordinator'
UNION ALL
SELECT id, 'training.view' FROM staff_roles WHERE slug = 'training_coordinator'
UNION ALL
SELECT id, 'training.manage' FROM staff_roles WHERE slug = 'training_coordinator'
UNION ALL
SELECT id, 'messages.view' FROM staff_roles WHERE slug = 'training_coordinator'
UNION ALL
SELECT id, 'messages.manage' FROM staff_roles WHERE slug = 'training_coordinator'
UNION ALL
SELECT id, 'reports.view' FROM staff_roles WHERE slug = 'training_coordinator'
SQL,
    'down' => 'DELETE p FROM staff_role_permissions p JOIN staff_roles r ON r.id = p.role_id WHERE r.built_in = 1',
];

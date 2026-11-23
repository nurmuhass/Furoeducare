<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Admin;

final class StaffPermissions
{
    public const ALL = [
        'overview.view' => 'View administration overview',
        'teachers.view' => 'View teacher accounts', 'schools.view' => 'View school accounts', 'parents.view' => 'View parent accounts',
        'accounts.manage' => 'Suspend and restore accounts',
        'verification.view' => 'View verification queues', 'verification.manage' => 'Record verification decisions',
        'documents.view' => 'View private verification documents',
        'directory.view' => 'Review public teacher listings', 'directory.manage' => 'Publish or withdraw teacher listings',
        'vacancies.view' => 'View vacancies', 'vacancies.manage' => 'Moderate and publish vacancies',
        'applications.view' => 'View applications', 'applications.manage' => 'Manage applications',
        'tutoring.view' => 'View tutoring requests', 'tutoring.manage' => 'Manage tutoring requests',
        'candidates.view' => 'View candidate cases', 'candidates.manage' => 'Manage candidate shortlists',
        'cv_requests.view' => 'View candidate information requests', 'cv_access.manage' => 'Decide and revoke information access',
        'placements.view' => 'View interviews and placements', 'placements.manage' => 'Manage interviews and final placements',
        'library.view' => 'View library management', 'library.manage' => 'Manage library content and access',
        'training.view' => 'View training management', 'training.manage' => 'Manage training programmes and sessions',
        'messages.view' => 'View management conversations', 'messages.manage' => 'Manage and reply to conversations',
        'email.view' => 'View safe email delivery monitoring', 'reports.view' => 'View permitted aggregate reports',
        'staff.view' => 'View staff assignments and roles', 'staff.manage' => 'Manage staff assignments and roles',
        'audit.view' => 'View audit activity', 'settings.view' => 'View operational settings', 'settings.manage' => 'Change operational settings',
        'privacy.view' => 'View data rights requests', 'privacy.manage' => 'Manage data rights responses',
    ];

    public const BUILT_INS = [
        'super_admin' => ['name' => 'Super Admin', 'permissions' => null],
        'recruitment_officer' => ['name' => 'Recruitment Officer', 'permissions' => [
            'overview.view', 'teachers.view', 'schools.view', 'parents.view', 'directory.view',
            'vacancies.view', 'vacancies.manage', 'applications.view', 'applications.manage',
            'tutoring.view', 'tutoring.manage', 'candidates.view', 'candidates.manage', 'cv_requests.view',
            'placements.view', 'placements.manage', 'messages.view', 'messages.manage', 'reports.view',
        ]],
        'verification_officer' => ['name' => 'Verification Officer', 'permissions' => [
            'overview.view', 'teachers.view', 'schools.view', 'verification.view', 'verification.manage',
            'documents.view', 'directory.view', 'directory.manage',
        ]],
        'library_manager' => ['name' => 'Library Manager', 'permissions' => ['overview.view', 'library.view', 'library.manage', 'reports.view']],
        'training_coordinator' => ['name' => 'Training Coordinator', 'permissions' => [
            'overview.view', 'schools.view', 'training.view', 'training.manage', 'messages.view', 'messages.manage', 'reports.view',
        ]],
    ];

    public static function navigation(array $permissions): array
    {
        $items = [
            ['Overview', '/dashboard/admin', 'overview.view', 'Workspace'],
            ['Teacher management', '/dashboard/admin/accounts?type=teacher', 'teachers.view', 'Accounts'],
            ['School management', '/dashboard/admin/accounts?type=school', 'schools.view', 'Accounts'],
            ['Parent management', '/dashboard/admin/accounts?type=parent', 'parents.view', 'Accounts'],
            ['Verification queue', '/dashboard/admin/verification', 'verification.view', 'Verification'],
            ['Public teacher listings', '/dashboard/admin/listings', 'directory.view', 'Verification'],
            ['Vacancy moderation', '/dashboard/admin/vacancies', 'vacancies.view', 'Recruitment'],
            ['Job applications', '/dashboard/admin/vacancies/applications', 'applications.view', 'Recruitment'],
            ['Tutoring requests', '/dashboard/admin/tutoring-requests', 'tutoring.view', 'Recruitment'],
            ['Candidate shortlisting', '/dashboard/admin/candidate-requests', 'candidates.view', 'Recruitment'],
            ['CV access requests', '/dashboard/admin/cv-requests', 'cv_requests.view', 'Recruitment'],
            ['Interviews and placements', '/dashboard/admin/placements', 'placements.view', 'Recruitment'],
            ['Digital library', '/dashboard/admin/library', 'library.view', 'Services'],
            ['Training and workshops', '/dashboard/admin/training', 'training.view', 'Services'],
            ['Internal messaging', '/dashboard/admin/messages', 'messages.view', 'Operations'],
            ['Email delivery', '/dashboard/admin/email', 'email.view', 'Operations'],
            ['Reports and analytics', '/dashboard/admin/reports', 'reports.view', 'Operations'],
            ['Staff and roles', '/dashboard/admin/staff', 'staff.view', 'Administration'],
            ['Audit logs', '/dashboard/admin/audit', 'audit.view', 'Administration'],
            ['System settings', '/dashboard/admin/settings', 'settings.view', 'Administration'],
            ['Data rights', '/dashboard/admin/privacy-requests', 'privacy.view', 'Administration'],
        ];
        $result = [];
        foreach ($items as [$label, $path, $permission, $group]) {
            if (in_array($permission, $permissions, true)) {
                $result[] = compact('label', 'path', 'permission', 'group');
            }
        }
        return $result;
    }
}

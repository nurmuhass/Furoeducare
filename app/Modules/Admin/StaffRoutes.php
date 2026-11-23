<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Admin;

use Furoeducare\Core\Request;

final class StaffRoutes
{
    /** Every management route is classified explicitly; null is a denied route. */
    public static function required(Request $request): ?array
    {
        $path = $request->path;
        $write = !in_array($request->method, ['GET', 'HEAD'], true);
        if ($path === '/dashboard/admin') {
            return $write ? null : ['overview.view'];
        }
        $simple = [
            '/dashboard/admin/search' => 'overview.view', '/dashboard/admin/listings' => 'directory.view',
            '/dashboard/admin/cv-requests' => 'cv_requests.view', '/dashboard/admin/email' => 'email.view',
            '/dashboard/admin/reports' => 'reports.view', '/dashboard/admin/audit' => 'audit.view',
            '/dashboard/admin/reports/export' => 'reports.view',
        ];
        if (isset($simple[$path])) {
            return $write ? null : [$simple[$path]];
        }
        if (in_array($path, ['/dashboard/admin/accounts', '/dashboard/admin/accounts/view'], true)) {
            $permission = match ($request->query('type') ?: 'teacher') {
                'teacher' => 'teachers.view', 'school' => 'schools.view', 'parent' => 'parents.view', default => null,
            };
            return $write || $permission === null ? null : [$permission];
        }
        if ($path === '/dashboard/admin/accounts/action') {
            return $write ? ['accounts.manage'] : null;
        }
        foreach (['staff' => 'staff', 'roles' => 'staff', 'settings' => 'settings'] as $suffix => $scope) {
            if ($path === '/dashboard/admin/' . $suffix) {
                return [$scope . ($write ? '.manage' : '.view')];
            }
        }
        if ($path === '/dashboard/admin/verification/documents/download') {
            return $write ? null : ['documents.view'];
        }
        if (in_array($path, ['/dashboard/admin/verification', '/dashboard/admin/verification/teachers',
            '/dashboard/admin/verification/documents', '/dashboard/admin/verification/corrections',
            '/dashboard/admin/verification/teachers/review', '/dashboard/admin/verification/documents/review',
            '/dashboard/admin/schools', '/dashboard/admin/schools/review'], true)) {
            return ['verification.' . ($write ? 'manage' : 'view')];
        }
        if ($path === '/dashboard/admin/directory') {
            return ['directory.' . ($write ? 'manage' : 'view')];
        }
        if ($path === '/dashboard/admin/directory/requests') {
            return $write ? null : ['candidates.view'];
        }
        if ($path === '/dashboard/admin/candidate-requests') {
            if (!$write) {
                return $request->query('queue') === 'disclosure' || $request->query('disclosure') !== ''
                    ? ['candidates.view', 'cv_requests.view'] : ['candidates.view'];
            }
            return [in_array($request->form('action'), ['decide', 'revoke'], true) ? 'cv_access.manage' : 'candidates.manage'];
        }
        foreach ([
            '/dashboard/admin/vacancies/applications' => 'applications', '/dashboard/admin/vacancies/application' => 'applications',
            '/dashboard/admin/vacancies' => 'vacancies', '/dashboard/admin/tutoring-requests' => 'tutoring',
            '/dashboard/admin/placements' => 'placements', '/dashboard/admin/library' => 'library',
            '/dashboard/admin/library/file' => 'library', '/dashboard/admin/training' => 'training',
            '/dashboard/admin/training/requests' => 'training', '/dashboard/admin/messages' => 'messages',
            '/dashboard/admin/messages/read' => 'messages', '/dashboard/admin/privacy-requests' => 'privacy',
        ] as $route => $scope) {
            if ($path === $route) {
                return [$scope . ($write ? '.manage' : '.view')];
            }
        }
        return null;
    }
}

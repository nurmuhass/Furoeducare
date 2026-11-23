<?php
declare(strict_types=1);

namespace Furoeducare\UI;

use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Admin\StaffPermissions;

final class DashboardNavigation
{
    public static function context(array $roles, string $path, ?string $remembered = null, ?Role $effective = null): ?Role
    {
        foreach (Role::cases() as $role) {
            if ($path === $role->dashboard() || str_starts_with($path, $role->dashboard() . '/')) {
                return in_array($role, $roles, true) ? $role : null;
            }
        }
        $shared = ['/messages', '/notifications', '/settings/account', '/settings/email-change', '/settings/notifications', '/digital-library',
            '/find-teachers', '/candidate-requests', '/placements', '/privacy/controls', '/teaching-jobs', '/training'];
        foreach ($shared as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                // Some existing shared workflows select an owner category. Their
                // server-selected category takes precedence over a UI preference.
                if ($effective !== null && in_array($effective, $roles, true)) return $effective;
                $previous = Role::tryFrom($remembered ?? '');
                return $previous !== null && in_array($previous, $roles, true) ? $previous : ($roles[0] ?? null);
            }
        }
        return null;
    }

    public static function items(Role $role, array $permissions = []): array
    {
        $root = $role->dashboard();
        $items = $role === Role::Admin ? StaffPermissions::navigation($permissions) : [];
        $add = static function (string $label, string $path, string $group) use (&$items): void {
            $items[] = compact('label', 'path', 'group');
        };
        if ($role !== Role::Admin) $add('Overview', $root, 'Workspace');
        if ($role === Role::Teacher) {
            foreach (['profile' => 'Profile', 'documents' => 'CV / Documents', 'availability' => 'Availability'] as $tab => $label) $add($label, $root . '?tab=' . $tab, 'Professional profile');
            foreach (['jobs' => 'Jobs', 'applications' => 'Applications', 'placements' => 'Opportunities', 'saved-jobs' => 'Saved Jobs'] as $tab => $label) $add($label, $root . '?tab=' . $tab, 'Recruitment');
        } elseif ($role === Role::School) {
            $add('School Profile', $root . '?tab=profile', 'Workspace');
            $add('Recruitment', $root . '?tab=drafts', 'Recruitment');
            $add('Vacancies', $root . '/vacancies', 'Recruitment');
            $add('Find Teachers', '/find-teachers', 'Recruitment');
            $add('Teacher Requests', '/find-teachers/requests', 'Recruitment');
            $add('Shortlists', '/candidate-requests', 'Recruitment');
            $add('Saved Teachers', '/find-teachers/saved', 'Recruitment');
            $add('Training and Workshops', $root . '/training', 'School development');
        } elseif ($role === Role::Parent) {
            $add('Find Teachers', '/find-teachers', 'Tutoring');
            foreach (['request-teacher' => 'Request Lesson Teacher', 'requests' => 'My Requests', 'recommended' => 'Recommended Teachers', 'saved' => 'Saved Teachers', 'arrangements' => 'Lesson Arrangements'] as $tab => $label) $add($label, $root . '?tab=' . $tab, 'Tutoring');
        }
        // All staff keep access to public resources and their own account preferences.
        // Administrative module links remain controlled by the existing permission map.
        if ($role === Role::Admin) {
            foreach ($items as &$item) {
                if ($item['permission'] === 'library.view') $item['label'] = 'Library Management';
                if ($item['permission'] === 'messages.view') $item['label'] = 'Messages';
            }
            unset($item);
            if (!in_array('overview.view', $permissions, true)) $items[] = ['label' => 'Overview', 'path' => '', 'group' => 'Workspace', 'hint' => 'Overview permission required'];
        }
        $add('Digital Library', '/digital-library', 'Resources');
        if ($role !== Role::Admin) {
            $add('Messages', '/messages', 'Account');
        } elseif (!in_array('messages.view', $permissions, true)) {
            $items[] = ['label' => 'Messages', 'path' => '', 'group' => 'Account', 'hint' => 'Messaging permission required'];
        }
        $add('Notifications', '/notifications', 'Account');
        $add('Settings', '/settings/account', 'Account');
        return $items;
    }

    public static function active(array $items, string $path, array $query): ?string
    {
        if ($path === '/settings/email-change') $path = '/settings/account';
        $selected = null;
        $weight = -1;
        foreach ($items as $item) {
            if ($item['path'] === '') continue;
            $url = parse_url($item['path']);
            $target = $url['path'];
            parse_str($url['query'] ?? '', $required);
            if ($path !== $target && (!str_starts_with($path, $target . '/') || preg_match('~^/dashboard/(teacher|school|parent|admin)$~D', $target))) continue;
            if (array_diff_assoc($required, array_filter($query, 'is_scalar')) !== []) continue;
            if ($required === [] && str_starts_with($target, '/dashboard/') && isset($query['tab']) && $query['tab'] !== 'overview') continue;
            $score = strlen($target) + count($required) * 1000;
            if ($score > $weight) { $selected = $item['path']; $weight = $score; }
        }
        return $selected;
    }
}

<?php
declare(strict_types=1);

use Furoeducare\UI\DashboardNavigation;
use Furoeducare\UI\Components;
use Furoeducare\Modules\Auth\Role;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

try {
    foreach (Role::cases() as $role) {
        $links = DashboardNavigation::items($role, ['overview.view', 'library.view']);
        foreach (['Overview', 'Digital Library', 'Messages', 'Notifications', 'Settings'] as $label) {
            expect(in_array($label, array_column($links, 'label'), true), $role->value . ' has ' . $label);
        }
    }
    $school = DashboardNavigation::items(Role::School);
    foreach (['Recruitment', 'Vacancies', 'Find Teachers', 'Teacher Requests', 'Shortlists', 'Saved Teachers', 'Training and Workshops'] as $label) {
        expect(in_array($label, array_column($school, 'label'), true), 'School link: ' . $label);
    }
    $teacher = DashboardNavigation::items(Role::Teacher);
    expect(DashboardNavigation::active($teacher, '/dashboard/teacher', ['tab' => 'documents']) === '/dashboard/teacher?tab=documents', 'Specific query tab is active.');
    expect(DashboardNavigation::active($teacher, '/dashboard/teacher', []) === '/dashboard/teacher', 'Overview default.');
    expect(DashboardNavigation::active($teacher, '/digital-library/history', []) === '/digital-library', 'Resource children preserve context.');
    expect(DashboardNavigation::active($teacher, '/settings/email-change', []) === '/settings/account', 'Email confirmation retains Settings navigation.');
    $admin = DashboardNavigation::items(Role::Admin, ['overview.view', 'library.view']);
    expect(!in_array('/dashboard/admin/staff', array_column($admin, 'path'), true), 'Staff link stays permission filtered.');
    expect(DashboardNavigation::context([Role::Teacher, Role::Admin], '/dashboard/admin', 'teacher') === Role::Admin, 'Explicit role route wins.');
    expect(DashboardNavigation::context([Role::Teacher], '/dashboard/admin', 'admin') === null, 'Forbidden role never gets shell.');
    expect(DashboardNavigation::context([Role::Teacher, Role::School], '/messages', 'school') === Role::School, 'Shared page retains valid role.');
    expect(DashboardNavigation::context([Role::Teacher], '/', 'teacher') === null, 'Public homepage retains public shell.');
    expect(DashboardNavigation::context([Role::Teacher], '/digital-libraryish', 'teacher') === null, 'Shared route matching respects boundary.');
    expect(DashboardNavigation::context([Role::Teacher], '/messages', 'admin') === Role::Teacher, 'Invalid remembered role cannot escalate context.');
    expect(DashboardNavigation::context([Role::School, Role::Parent], '/candidate-requests', 'school', Role::Parent) === Role::Parent, 'Actual shared workflow owner role overrides remembered presentation.');
    expect(DashboardNavigation::context([Role::School], '/candidate-requests', 'school', Role::Admin) === Role::School, 'Effective context still requires account membership.');
    echo "PASS role navigation, query tabs, shared-page context and permission filtering.\n";

    $html = Components::metric('<script>x</script>', 3, '/dashboard/parent');
    expect(!str_contains($html, '<script>') && str_contains($html, '>3<'), 'Metrics escape labels and use supplied data.');
    expect(str_contains(Components::status('changes_required'), 'Changes required'), 'Statuses readable without colour.');
    expect(!str_contains(Components::empty('Nothing', '<img src=x>'), '<img'), 'Empty states escape.');
    expect(str_contains(Components::error(['<x>']), 'role="alert"'), 'Errors announced.');
    $html = Components::table([['id' => 1, 'status' => 'pending', 'name' => '<script>x</script>']], ['name' => 'Name', 'status' => 'Status'], 'Current records');
    expect(str_contains($html, '<caption>Current records</caption>') && str_contains($html, 'scope="col"') && !str_contains($html, '<script>'), 'Table caption, column headings and escaping.');
    $html = Components::pagination('/notifications', ['category' => 'messages', 'before' => 40], 20);
    expect(str_contains($html, 'before=20') && str_contains($html, 'category=messages') && str_contains($html, 'aria-label="Pagination"'), 'Cursor pagination retains filters and replaces cursor.');
    expect(!str_contains(Components::metric('Unsafe', 0, '//evil.test'), 'href='), 'Links reject protocol-relative paths.');
    expect(str_contains(Components::search('/teaching-jobs', '<x>', 'Search jobs'), '&lt;x&gt;'), 'Search escapes retained query.');
    $html = Components::filters('/notifications', ['unread' => ['label' => 'Unread only', 'type' => 'checkbox'], 'q' => ['label' => 'Search']], ['unread' => '1', 'q' => '<script>']);
    expect(str_contains($html, ' checked') && !str_contains($html, '<script>') && str_contains($html, 'Clear filters'), 'Filters retain checked/query values and escape content.');
    echo "PASS reusable metrics, statuses, tables, search, pagination, empty and error states.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

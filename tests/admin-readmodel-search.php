<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Admin\AdminReadModel;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

// Read-only supplement for an already migrated, isolated administration schema.
$config = Config::load(dirname(__DIR__));
expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
    && preg_match('/\Afuroeducare_test_admin_[a-z0-9_]+\z/D', $config->databaseName) === 1,
    'Use an existing isolated loopback administration test schema.');
$db = Database::connect($config);
$read = new AdminReadModel(static fn (): PDO => $db);
$scopes = ['teachers.view', 'schools.view', 'parents.view', 'cv_requests.view', 'library.view', 'training.view',
    'vacancies.view', 'applications.view', 'tutoring.view', 'candidates.view', 'placements.view', 'messages.view'];
$types = ['teacher', 'school', 'parent', 'cv_request', 'library', 'training', 'vacancy', 'application', 'tutoring', 'candidate', 'placement', 'message'];
$paths = ['/dashboard/admin/accounts/view', '/dashboard/admin/accounts/view', '/dashboard/admin/accounts/view', '/dashboard/admin/cv-requests',
    '/dashboard/admin/library', '/dashboard/admin/training', '/dashboard/admin/vacancies', '/dashboard/admin/vacancies/application',
    '/dashboard/admin/tutoring-requests', '/dashboard/admin/candidate-requests', '/dashboard/admin/placements', '/dashboard/admin/messages'];
$permissions = [];
foreach ($scopes as $index => $permission) {
    $permissions[] = $permission;
    $rows = $read->search($permissions, 'PrivateTeacher952', PHP_INT_MAX);
    expect(count($rows) <= 21, 'Mixed-collation source unions remain bounded.');
    foreach ($rows as $row) {
        expect(in_array($row['type'], array_slice($types, 0, $index + 1), true), 'Union results contain permitted types only.');
    }
    // Execute each source separately as well as all accumulated combinations.
    $read->search([$permission], 'search-fixture-%_!', PHP_INT_MAX);
    foreach (['PrivateTeacher952', 'request', 'placement', 'support', 'a'] as $query) {
        foreach ($read->search([$permission], $query, PHP_INT_MAX) as $row) {
            expect($row['type'] === $types[$index], 'An individual search scope returns only its own source.');
            expect(parse_url($row['path'], PHP_URL_PATH) === $paths[$index], 'Each result targets its registered management route.');
            parse_str((string) parse_url($row['path'], PHP_URL_QUERY), $target);
            expect(isset($target['id']) && ctype_digit((string) $target['id']), 'Result paths carry a real record identifier.');
            expect(array_keys($row) === ['id', 'label', 'detail', 'path', 'type', 'status'], 'Search exposes only the safe result projection.');
            if (in_array($row['type'], ['teacher', 'school', 'parent'], true)) {
                expect(($target['type'] ?? '') === $row['type'], 'Account results include their permission-gated account type.');
            }
            if (in_array($row['type'], ['vacancy', 'tutoring', 'candidate'], true)) {
                expect($row['status'] !== 'draft', 'Private owner drafts do not appear in management search.');
            }
            if ($row['type'] === 'message') {
                $visible = $db->prepare("SELECT COUNT(*) FROM message_conversations WHERE id = ? AND purged_at IS NULL
                    AND (retention_hold = 1 OR status <> 'closed' OR closed_at > UTC_TIMESTAMP(6) - INTERVAL 365 DAY)");
                $visible->execute([(int) $target['id']]);
                expect((int) $visible->fetchColumn() === 1, 'Message results respect the repository retention predicate.');
            }
        }
    }
}
expect($read->search([], 'PrivateTeacher952', PHP_INT_MAX) === [], 'No search permission discloses no results.');
$first = $read->search($scopes, 'PrivateTeacher952', PHP_INT_MAX);
if ($first !== []) {
    $cursor = (int) $first[array_key_last($first)]['id'];
    foreach ($read->search($scopes, 'PrivateTeacher952', $cursor) as $row) {
        expect((int) $row['id'] < $cursor, 'Composite search cursors paginate strictly below the prior page.');
    }
}
echo "PASS twelve search sources, mixed-collation unions, scoped routes, draft/retention visibility and cursor queries.\n";

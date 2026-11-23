<?php
declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\UI\DashboardNavigation;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';

$root = dirname(__DIR__);
$ids = [];
$db = null;
$work = $root . '/storage/local-preview/dashboard-ui-' . bin2hex(random_bytes(6));
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_admin_[a-f0-9]{12}\z/', $config->databaseName) === 1,
        'Use an isolated migrated administration test schema; never application data.');
    $db = Database::connect($config);
    expect((int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === count(glob($root . '/database/migrations/*.php')), 'All current migrations are required.');
    mkdir($work, 0700, true);
    $password = 'Dashboard UI synthetic fixture 470!';
    foreach (Role::cases() as $role) {
        $email = 'ui-' . $role->value . '-' . bin2hex(random_bytes(5)) . '@example.test';
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $db->lastInsertId();
        $ids[$role->value] = ['id' => $id, 'email' => $email];
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $role->value]);
        if ($role === Role::Admin) {
            $db->prepare("INSERT INTO staff_user_roles (user_id, role_id) SELECT ?, id FROM staff_roles WHERE slug = 'library_manager'")->execute([$id]);
        } elseif ($role === Role::Teacher) {
            $db->prepare("INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone, terms_version, privacy_version, accepted_at, sections) VALUES (?, 'teacher', 'Synthetic', 'Teacher', '+2348012345678', 'fixture', 'fixture', UTC_TIMESTAMP(6), JSON_OBJECT())")->execute([$id]);
        } elseif ($role === Role::School) {
            $db->prepare("INSERT INTO school_onboarding (user_id, school_name, review_status) VALUES (?, 'Synthetic UI School', 'pending')")->execute([$id]);
        } else {
            $db->prepare("INSERT INTO parent_profiles (user_id, first_name, last_name, phone, state_code, city, terms_version, privacy_version, accepted_at) VALUES (?, 'Synthetic', 'Parent', '+2348012345678', 'LA', 'Ikeja', 'fixture', 'fixture', UTC_TIMESTAMP(6))")->execute([$id]);
        }
    }
    $db->prepare("INSERT INTO candidate_requests (requester_id, requester_role, category, purpose, requirements) VALUES (?, 'parent', 'tutoring', 'Synthetic direct-link check', 'Synthetic UI fixture only')")->execute([$ids['parent']['id']]);
    $caseId = (int) $db->lastInsertId();
    serve($root, ['APP_ENV' => 'development', 'AUTH_SESSION_DIR' => $work . '/sessions', 'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log',
        static function (string $base) use ($root, $work, $ids, $password, $db, $caseId): void {
            foreach (Role::cases() as $role) {
                $browser = new AuthBrowser($base);
                redirectIs($browser->submit('/login', ['email' => $ids[$role->value]['email'], 'password' => $password]), $role->dashboard());
                [$status, , $html] = $browser->send($role->dashboard());
                expect($status === 200, $role->value . ' overview loads: ' . $status);
                file_put_contents($work . '/' . $role->value . '-raw.html', $html);
                expect(substr_count($html, 'id="main"') === 1 && substr_count($html, 'id="dashboard-navigation"') === 1, $role->value . ' needs one main and shared navigation; found ' . substr_count($html, 'id="main"') . '/' . substr_count($html, 'id="dashboard-navigation"'));
                expect(!str_contains($html, 'data-site-header') && str_contains($html, '/assets/dashboard.js'), 'Shared shell replaces public/legacy frame.');
                $preview = preg_replace('~(["\'])/assets/~', '$1file:///' . str_replace('\\', '/', $root) . '/public/assets/', $html);
                file_put_contents($work . '/' . $role->value . '.html', $preview);
                foreach (['/digital-library', '/digital-library/saved', '/digital-library/history', '/messages', '/notifications', '/settings/notifications'] as $path) {
                    [$code, , $shared] = $browser->send($path);
                    $deniedMessages = $role === Role::Admin && $path === '/messages';
                    expect($code === ($deniedMessages ? 403 : 200), $role->value . ' shared route ' . $path . ': ' . $code);
                    expect(str_contains($shared, 'data-dashboard-workspace') && str_contains($shared, $role->label() . ' dashboard navigation'), 'Shared route retains originating context.');
                }
                foreach (DashboardNavigation::items($role, $role === Role::Admin ? ['overview.view', 'library.view', 'library.manage', 'reports.view'] : []) as $link) {
                    if ($link['path'] === '') continue;
                    [$code] = $browser->send($link['path']);
                    expect(in_array($code, [200, 303, 403], true), $role->value . ' navigation route ' . $link['path'] . ': ' . $code);
                }
                if ($role === Role::School) expect($browser->send('/dashboard/school/vacancies')[0] === 403, 'Pending school recruitment stays restricted.');
                if ($role === Role::Admin) expect($browser->send('/dashboard/admin/staff')[0] === 403, 'Library manager cannot access staff.');
                if ($role === Role::Parent) {
                    $response = $browser->submit('/dashboard/parent/profile', ['first_name' => ''], '/dashboard/parent?tab=settings');
                    expect($response[0] === 422 && str_contains($response[2], 'data-error-summary') && str_contains($response[2], 'aria-invalid="true"'), 'Server validation errors stay in shell with accessible fields.');
                }
                redirectIs($browser->submit('/logout', [], $role->dashboard()), '/login');
            }
            $browser = new AuthBrowser($base);
            redirectIs($browser->submit('/login', ['email' => $ids['parent']['email'], 'password' => $password]), '/dashboard/parent');
            $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'school'")->execute([$ids['parent']['id']]);
            $db->prepare("INSERT INTO school_onboarding (user_id, school_name, review_status) VALUES (?, 'Synthetic multi-role school', 'pending')")->execute([$ids['parent']['id']]);
            expect($browser->send('/dashboard/school')[0] === 200, 'Synthetic multi-role account enters School context.');
            foreach (['/candidate-requests', '/find-teachers'] as $path) {
                [$code, , $html] = $browser->send($path);
                expect($code === 200 && str_contains($html, 'Parent / Guardian dashboard navigation'), 'Shell follows effective workflow owner category: ' . $path);
            }
            $browser->send('/dashboard/school');
            [$code, , $detail] = $browser->send('/candidate-requests?id=' . $caseId);
            expect($code === 200 && str_contains($detail, 'Parent / Guardian dashboard navigation'), 'Direct candidate detail uses effective owner role.');
            $browser->send('/dashboard/school');
            [$code, , $problem] = $browser->send('/candidate-requests/profile?id=0');
            expect($code === 404 && str_contains($problem, 'Parent / Guardian dashboard navigation'), 'Direct candidate profile error retains effective owner context.');
            echo "PASS four real dashboard overviews, shared-service context, all sidebar routes, validation states and existing access restrictions.\n";
            echo "PASS multi-role shared requests use the backend-selected owner category.\n";
            echo 'Synthetic rendered fixtures: ' . $work . PHP_EOL;
        });
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($db !== null && isset($caseId)) $db->prepare('DELETE FROM candidate_requests WHERE id = ?')->execute([$caseId]);
    if ($db !== null) foreach ($ids as $account) $db->prepare('DELETE FROM users WHERE id = ?')->execute([$account['id']]);
}

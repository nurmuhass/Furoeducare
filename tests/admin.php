<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Admin\StaffAccess;
use Furoeducare\Modules\Admin\StaffPermissions;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';

function adminStatus(array $response, int $expected, string $message): void
{
    expect($response[0] === $expected, "$message Expected HTTP $expected, got {$response[0]}. " . substr(strip_tags($response[2]), -700));
}

function adminRejected(array $response, string $message): void
{
    expect(in_array($response[0], [403, 409, 422], true), "$message Expected a refused change, got HTTP {$response[0]}.");
}

$root = dirname(__DIR__);
$work = $root . '/storage/local-preview/admin-' . bin2hex(random_bytes(6));
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_admin_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback development schema named furoeducare_test_admin_<suffix>.');
    $db = Database::connect($config);
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    if (getenv('ADMIN_TEST_REUSE_SCHEMA') === '1') {
        expect(preg_match('/\Afuroeducare_test_admin_[a-f0-9]{12}\z/', $config->databaseName) === 1,
            'Fixture reset is restricted to an administration suite schema created by use-test-db.ps1.');
        expect(getenv('ADMIN_TEST_REUSE_SCHEMA_NAME') === $config->databaseName, 'Confirm the exact isolated schema before resetting its fixtures.');
        $status = $migrations->status();
        expect(count($status) === count(glob($root . '/database/migrations/*.php')) && array_values(array_unique($status)) === ['applied'], 'Reused schemas require all current migrations.');
        expect((int) $db->query("SELECT COUNT(*) FROM users WHERE email = 'super@example.test'")->fetchColumn() === 1,
            'Reused schemas require the administration suite fixture marker.');
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                if (!in_array($table, ['schema_migrations', 'roles', 'staff_roles', 'staff_role_permissions'], true)) {
                    $db->exec('DELETE FROM `' . str_replace('`', '``', $table) . '`');
                }
            }
            $db->exec('DELETE FROM staff_role_permissions WHERE role_id IN (SELECT id FROM staff_roles WHERE built_in = 0)');
            $db->exec('DELETE FROM staff_roles WHERE built_in = 0');
            $permissionSeed = require $root . '/database/migrations/0081_seed_staff_role_permissions.php';
            $db->exec($permissionSeed['down']);
            $db->exec($permissionSeed['up']);
        } finally {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    } else {
        expect($db->query('SHOW TABLES')->fetchAll() === [], 'Admin tests require a fresh disposable schema.');
        expect(count($migrations->migrate()) === count(glob($root . '/database/migrations/*.php')), 'All administration migrations apply.');
    }
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
    expect((int) $db->query('SELECT COUNT(*) FROM staff_user_roles')->fetchColumn() === 0, 'A fresh installation grants no implicit staff access.');
    expect(mkdir($work, 0700, true), 'Create administration HTTP artifacts.');
    $password = 'Administration synthetic fixture 952!';
    $ids = [];
    $assignments = ['super' => 'super_admin', 'recruitment' => 'recruitment_officer', 'verification' => 'verification_officer',
        'library' => 'library_manager', 'training' => 'training_coordinator', 'bare' => null, 'target' => null, 'teacher' => null];
    foreach ($assignments as $name => $staffRole) {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
            ->execute([$name . '@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $ids[$name] = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')
            ->execute([$ids[$name], $name === 'teacher' ? 'teacher' : 'admin']);
        if ($staffRole !== null) {
            $db->prepare('INSERT INTO staff_user_roles (user_id, role_id) SELECT ?, id FROM staff_roles WHERE slug = ?')
                ->execute([$ids[$name], $staffRole]);
        }
    }
    $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'teacher'")->execute([$ids['super']]);
    $db->prepare("INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone, terms_version, privacy_version, accepted_at, sections)
        VALUES (?, 'teacher', 'PrivateTeacher952', '<script>alert(952)</script>', '+2348012345678', 'fixture', 'fixture', UTC_TIMESTAMP(6), JSON_OBJECT())")
        ->execute([$ids['teacher']]);
    $roleIds = $db->query('SELECT slug, id FROM staff_roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    $access = new StaffAccess(static fn (): PDO => $db);
    expect($access->permissions($ids['bare']) === [], 'The legacy admin marker alone carries no management permissions.');
    foreach ($assignments as $name => $slug) {
        if ($slug === null) continue;
        $expected = StaffPermissions::BUILT_INS[$slug]['permissions'] ?? array_keys(StaffPermissions::ALL);
        $actual = $access->permissions($ids[$name]);
        sort($expected);
        sort($actual);
        expect($actual === $expected, 'Each built-in role receives exactly its declared permission set.');
    }
    $db->prepare('INSERT INTO staff_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$ids['teacher'], $roleIds['super_admin']]);
    expect($access->permissions($ids['teacher']) === [], 'A staff assignment cannot turn a nonstaff account into an administrator.');
    $db->exec('DELETE FROM staff_user_roles WHERE user_id = ' . $ids['teacher']);
    try {
        Database::transaction($db, static fn (PDO $db) => StaffAccess::protectLastSuper($db, $ids['super']));
        throw new RuntimeException('The last active Super Admin was not protected.');
    } catch (DomainException $error) {
        expect($error->getCode() === 409, 'Last Super Admin changes require a conflict response.');
    }
    serve($root, ['APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000', 'AUTH_SESSION_DIR' => $work . '/sessions',
        'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log',
        static function (string $base) use ($db, $ids, $password, $roleIds): void {
        $prefix = '/dashboard/admin';
        $guest = new AuthBrowser($base);
        redirectIs($guest->send($prefix), '/login');
        $browsers = [];
        foreach ($ids as $name => $id) {
            $browsers[$name] = new AuthBrowser($base);
            adminStatus($browsers[$name]->submit('/login', ['email' => $name . '@example.test', 'password' => $password]), 303, 'Fixture login.');
        }
        $routes = ['/dashboard/admin', "$prefix/accounts?type=teacher", "$prefix/accounts?type=school", "$prefix/accounts?type=parent",
            "$prefix/staff", "$prefix/roles", "$prefix/settings", "$prefix/email", "$prefix/reports", "$prefix/audit", "$prefix/cv-requests",
            "$prefix/library", "$prefix/training", "$prefix/training/requests", "$prefix/verification", "$prefix/listings",
            "$prefix/vacancies", "$prefix/candidate-requests", "$prefix/placements", "$prefix/messages"];
        foreach ($routes as $path) {
            adminStatus($browsers['super']->send($path), 200, 'Super Admin can read each workspace destination.');
            adminStatus($browsers['bare']->send($path), 403, 'Coarse admin role alone grants no workspace access.');
            adminStatus($browsers['teacher']->send($path), 403, 'Teacher cannot read management routes.');
        }
        $allowed = ['recruitment' => "$prefix/candidate-requests", 'verification' => "$prefix/verification", 'library' => "$prefix/library", 'training' => "$prefix/training"];
        foreach ($allowed as $name => $path) {
            $page = $browsers[$name]->send($path);
            adminStatus($page, 200, 'Assigned role reads its own module.');
            expect(str_contains($page[1]['cache-control'] ?? '', 'no-store'), 'Workspace responses prevent private caching.');
            expect(str_contains($page[2], 'id="dashboard-navigation"') && str_contains($page[2], 'class="dashboard-main admin-main"')
                && str_contains($page[2], '/assets/theme.js') && str_contains($page[2], 'data-theme-picker'), 'Legacy module pages use the shared navigation and theme shell.');
            expect(!str_contains($page[2], 'href="/dashboard/admin/staff"') && !str_contains($page[2], 'href="/dashboard/admin/settings"'), 'Restricted navigation hides staff and settings.');
            foreach (["$prefix/staff", "$prefix/settings", "$prefix/audit"] as $denied) {
                adminStatus($browsers[$name]->send($denied), 403, 'Direct links cannot bypass role navigation.');
                foreach (['PUT', 'PATCH', 'DELETE'] as $override) {
                    $overridden = $browsers[$name]->send($denied, 'POST', ['_method' => $override]);
                    expect(in_array($overridden[0], [403, 405], true), 'POST method overrides cannot bypass permission gates.');
                }
            }
        }
        foreach (['recruitment', 'library', 'training', 'bare'] as $name) {
            adminStatus($browsers[$name]->send("$prefix/verification/documents/download?id=1", 'HEAD'), 403, 'HEAD cannot bypass private document permission.');
        }
        $search = $browsers['super']->send("$prefix/search?q=PrivateTeacher952");
        adminStatus($search, 200, 'Global search is available to authorized staff.');
        expect(str_contains($search[2], 'PrivateTeacher952') && !str_contains($search[2], '<script>alert(952)</script>'), 'Global search escapes private account text.');
        $restrictedSearch = $browsers['library']->send("$prefix/search?q=PrivateTeacher952");
        adminStatus($restrictedSearch, 200, 'Restricted staff can use scoped search.');
        expect(!str_contains($restrictedSearch[2], 'teacher@example.test'), 'Scoped search omits forbidden teacher results.');
        adminStatus($browsers['library']->send("$prefix/accounts/view?type=teacher&id=" . $ids['teacher']), 403, 'Library Manager cannot view a private teacher account.');
        adminStatus($browsers['recruitment']->send("$prefix/directory", 'POST', ['action' => 'approve']), 403, 'Recruitment cannot approve public directory entries.');
        foreach (['decide', 'revoke'] as $cvAction) {
            adminStatus($browsers['recruitment']->send("$prefix/candidate-requests", 'POST', ['action' => $cvAction]), 403, 'Recruitment cannot grant or revoke CV disclosure through candidate actions.');
        }
        expect(in_array($browsers['bare']->send("$prefix/unregistered-management-route")[0], [403, 404], true), 'Unknown management paths fail closed.');
        echo "PASS five staff roles, direct and override denials, private HEAD documents, navigation and coarse-admin isolation.\n";

        $super = $browsers['super'];
        $version = static fn (int $id): int => (int) $db->query('SELECT auth_version FROM users WHERE id = ' . $id)->fetchColumn();
        $action = "$prefix/accounts/action";
        $account = ['action' => 'suspend', 'type' => 'teacher', 'id' => $ids['teacher'], 'version' => $version($ids['teacher']), 'reason' => 'Synthetic review decision', 'confirmed' => '1'];
        adminStatus($super->send($action, 'POST', $account), 403, 'Account changes require CSRF.');
        adminStatus($super->submit($action, array_replace($account, ['confirmed' => '0']), "$prefix/accounts?type=teacher"), 422, 'Account changes require explicit confirmation.');
        adminStatus($super->submit($action, array_replace($account, ['version' => 0]), "$prefix/accounts?type=teacher"), 409, 'Stale account writes are rejected.');
        adminRejected($super->submit($action, array_replace($account, ['id' => $ids['super'], 'version' => $version($ids['super'])]), "$prefix/accounts?type=teacher"), 'Staff cannot suspend themselves.');
        adminStatus($super->submit($action, $account, "$prefix/accounts?type=teacher"), 303, 'Authorized confirmed suspension succeeds.');
        expect($version($ids['teacher']) === $account['version'] + 1, 'Suspension invalidates existing authentication.');
        redirectIs($browsers['teacher']->send('/dashboard/teacher'), '/login');

        $staff = "$prefix/staff";
        $assignment = ['action' => 'assign', 'target_id' => $ids['target'], 'version' => $version($ids['target']),
            'role_ids' => [$roleIds['library_manager']], 'reason' => 'Synthetic staff assignment', 'confirmed' => '1'];
        adminStatus($super->submit($staff, array_replace($assignment, ['confirmed' => '0'])), 422, 'Staff assignments require confirmation.');
        adminStatus($super->submit($staff, array_replace($assignment, ['version' => 0])), 409, 'Stale assignments fail.');
        adminRejected($super->submit($staff, array_replace($assignment, ['target_id' => $ids['super'], 'version' => $version($ids['super'])])), 'Self role changes are forbidden.');
        adminStatus($super->submit($staff, $assignment), 303, 'Assign a scoped staff role.');
        expect($version($ids['target']) === $assignment['version'] + 1, 'Role assignment invalidates authentication versions.');
        redirectIs($browsers['target']->send("$prefix/library"), '/login');
        $target = new AuthBrowser($base);
        adminStatus($target->submit('/login', ['email' => 'target@example.test', 'password' => $password]), 303, 'Reauthenticate assigned staff.');
        adminStatus($target->send("$prefix/library"), 200, 'New session receives assigned role.');
        $db->exec('DELETE FROM staff_user_roles WHERE user_id = ' . $ids['target']);
        adminStatus($target->send("$prefix/library"), 403, 'Role revocation is checked on the next existing session request.');
        echo "PASS confirmation, CSRF, stale account/assignment versions, self-protection and immediate role revocation.\n";

        $roles = "$prefix/roles";
        $role = ['action' => 'save', 'name' => 'Synthetic read-only librarian', 'permissions' => ['library.view'],
            'reason' => 'Synthetic least privilege role', 'confirmed' => '1'];
        adminStatus($super->submit($roles, $role), 303, 'Create an explicit custom role.');
        $custom = $db->query("SELECT id, revision FROM staff_roles WHERE name = 'Synthetic read-only librarian'")->fetch();
        expect(is_array($custom), 'Role creation is persisted.');
        adminStatus($super->submit($roles, $role + ['id' => $custom['id'], 'revision' => 0]), 409, 'Role editing rejects stale revisions.');
        $superRole = $db->query("SELECT id, revision FROM staff_roles WHERE slug = 'super_admin'")->fetch();
        adminRejected($super->submit($roles, $role + $superRole), 'The built-in Super Admin role is immutable.');
        adminStatus($super->submit($roles, array_replace($role, ['permissions' => ['unknown.permission']])), 422, 'Unknown permissions cannot be assigned.');
        $delegatorRole = array_replace($role, ['name' => 'Synthetic constrained staff manager', 'permissions' => ['staff.view', 'staff.manage', 'library.view', 'teachers.view', 'accounts.manage']]);
        adminStatus($super->submit($roles, $delegatorRole), 303, 'Create a constrained delegation role.');
        $delegatorId = (int) $db->query("SELECT id FROM staff_roles WHERE name = 'Synthetic constrained staff manager'")->fetchColumn();
        adminStatus($super->submit($staff, array_replace($assignment, ['version' => $version($ids['target']), 'role_ids' => [$delegatorId]])), 303, 'Assign constrained delegator.');
        $delegator = new AuthBrowser($base);
        adminStatus($delegator->submit('/login', ['email' => 'target@example.test', 'password' => $password]), 303, 'Constrained delegator signs in.');
        adminStatus($delegator->submit($staff, ['action' => 'assign', 'target_id' => $ids['bare'], 'version' => $version($ids['bare']),
            'role_ids' => [$roleIds['super_admin']], 'reason' => 'Synthetic escalation attempt', 'confirmed' => '1']), 403, 'Delegation cannot grant permissions beyond the actor.');
        expect((int) $db->query('SELECT COUNT(*) FROM staff_user_roles WHERE user_id = ' . $ids['bare'])->fetchColumn() === 0, 'A denied delegation leaves assignments untouched.');
        adminStatus($delegator->submit($staff, ['action' => 'assign', 'target_id' => $ids['super'], 'version' => $version($ids['super']),
            'role_ids' => [], 'reason' => 'Synthetic last administrator demotion', 'confirmed' => '1']), 409, 'Another staff manager cannot remove the last active Super Admin.');
        adminStatus($delegator->submit($action, ['action' => 'suspend', 'type' => 'teacher', 'id' => $ids['super'], 'version' => $version($ids['super']),
            'reason' => 'Synthetic last administrator suspension', 'confirmed' => '1'], "$prefix/accounts?type=teacher"), 409, 'Another account manager cannot suspend the last active Super Admin.');

        $settings = "$prefix/settings";
        $settingsPage = $super->send($settings);
        expect(preg_match('/name="revision" value="([0-9]+)"/', $settingsPage[2], $revision) === 1, 'Settings expose a current revision.');
        $fields = ['revision' => $revision[1], 'admin_notice' => 'Synthetic <script>alert(952)</script> notice',
            'support_email' => 'support@example.test', 'reason' => 'Synthetic operational change', 'confirmed' => '1'];
        foreach (['payments_enabled' => '1', 'DB_PASSWORD' => 'sensitive-setting-fixture'] as $key => $value) {
            adminStatus($super->submit($settings, $fields + [$key => $value]), 422, 'Settings reject payment toggles and environment secrets.');
        }
        adminStatus($super->submit($settings, $fields), 303, 'Allowlisted operational settings save.');
        adminStatus($super->submit($settings, $fields), 409, 'Settings reject stale revisions.');
        $shell = $super->send($prefix)[2];
        expect(str_contains($shell, 'Synthetic &lt;script&gt;') && !str_contains($shell, '<script>alert(952)</script>')
            && str_contains($shell, 'support@example.test'), 'Operational settings are used and escaped in the shared shell.');
        echo "PASS custom role validation, immutable Super Admin, stale revisions and allowlisted escaped operational settings.\n";

        $db->prepare("INSERT INTO email_outbox (user_id, recipient_email, template, payload, secret_payload, payload_hash, idempotency_key,
            status, provider_id, last_error_code) VALUES (?, ?, 'password_reset', ?, ?, ?, ?, 'failed', ?, 'transport_failure')")
            ->execute([$ids['target'], 'private-recipient-952@example.test', json_encode(['token' => 'private-payload-952']),
                'private-encrypted-secret-952', hash('sha256', 'fixture', true), hash('sha256', 'admin-email-fixture'), 'private-provider-id-952']);
        $db->prepare('INSERT INTO audit_logs (actor_user_id, action, context) VALUES (?, ?, ?)')
            ->execute([$ids['super'], 'test.admin_redaction', json_encode(['password' => 'private-audit-context-952'])]);
        $email = $super->send("$prefix/email");
        $audit = $super->send("$prefix/audit");
        adminStatus($email, 200, 'Email monitoring remains available.');
        adminStatus($audit, 200, 'Audit browsing remains available.');
        foreach (['private-recipient-952', 'private-payload-952', 'private-encrypted-secret-952', 'private-provider-id-952'] as $secret) {
            expect(!str_contains($email[2], $secret), 'Email monitoring omits recipient addresses, payloads, secrets and provider identifiers.');
        }
        expect(str_contains($email[2], 'password_reset') && str_contains($email[2], 'failed'), 'Email monitoring includes operational template and delivery state.');
        expect(str_contains($audit[2], 'test.admin_redaction') && !str_contains($audit[2], 'private-audit-context-952'), 'Audit browsing shows attributed actions without raw context.');
        expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%denied%'")->fetchColumn() > 0, 'Denied management requests are audited.');
        echo "PASS safe email status monitoring and audit context redaction.\n";
    });
    $bootstrap = static function (string $email) use ($root): array {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() !== false) array_push($command, '-c', php_ini_loaded_file());
        array_push($command, $root . '/bin/staff.php', 'bootstrap', $email);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, getenv());
        expect(is_resource($process), 'Start the trusted local bootstrap command.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    };
    expect($bootstrap('bare@example.test')[0] === 1, 'CLI bootstrap refuses to bypass an existing active Super Admin.');
    $db->exec('DELETE FROM staff_user_roles WHERE role_id = ' . (int) $roleIds['super_admin']);
    expect($bootstrap('missing-bootstrap@example.test')[0] === 1, 'CLI bootstrap requires an existing eligible account.');
    $result = $bootstrap('bare@example.test');
    expect($result[0] === 0, 'CLI bootstrap appoints a verified active account when no active Super Admin exists. ' . $result[2]);
    expect($access->has($ids['bare'], 'staff.manage') && $access->has($ids['bare'], 'cv_access.manage'), 'CLI bootstrap grants the complete Super Admin role.');
    expect($bootstrap('target@example.test')[0] === 1, 'CLI bootstrap cannot appoint another Super Admin after initialization.');
    echo "PASS trusted CLI bootstrap eligibility, one-time initialization and existing-Super protection.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

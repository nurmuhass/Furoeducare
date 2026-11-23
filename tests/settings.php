<?php
declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Auth\RateLimiter;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';

function settingsStatus(array $response, int $status, string $why): void {
    expect($response[0] === $status, "$why Expected $status; received {$response[0]}. " . substr(strip_tags($response[2]), -350));
}

$root = dirname(__DIR__);
$work = $root . '/storage/local-preview/settings-' . bin2hex(random_bytes(6));
try {
    putenv('MAIL_TRANSPORT=file');
    putenv('MAIL_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    putenv('MAIL_FROM=notifications@example.test');
    putenv('AUTH_MAIL_DIR=' . $work . '/mail');
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_settings_[a-f0-9]{12}\z/', $config->databaseName) === 1,
        'Settings tests require an isolated loopback settings schema.');
    $db = Database::connect($config);
    if (getenv('SETTINGS_TEST_REUSE_SCHEMA') === '1') {
        expect(getenv('SETTINGS_TEST_REUSE_SCHEMA_NAME') === $config->databaseName, 'Confirm the exact isolated settings schema before fixture reset.');
        $status = (new MigrationRunner($db, $root . '/database/migrations'))->status();
        expect(count($status) === count(glob($root . '/database/migrations/*.php')) && array_values(array_unique($status)) === ['applied'], 'Reused settings schema must be fully migrated.');
        expect((int) $db->query("SELECT COUNT(*) FROM users WHERE email = 'settings-teacher@example.test'")->fetchColumn() === 1, 'Reused settings schema requires its synthetic fixture marker.');
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                if (!in_array($table, ['schema_migrations', 'roles', 'staff_roles', 'staff_role_permissions'], true)) $db->exec('DELETE FROM `' . str_replace('`', '``', $table) . '`');
            }
        } finally { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); }
    } else expect($db->query('SHOW TABLES')->fetchAll() === [], 'Settings tests require a fresh empty schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    expect(array_values(array_unique($migrations->status())) === ['applied'], 'Apply all current migrations.');
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
    mkdir($work, 0700, true);
    $password = 'Settings synthetic fixture 680!';
    $changedPassword = 'Settings replacement fixture 681!';
    $query = static function (string $sql, array $args = []) use ($db): PDOStatement { $q = $db->prepare($sql); $q->execute($args); return $q; };
    $accounts = [];
    foreach (Role::cases() as $role) {
        $email = 'settings-' . $role->value . '@example.test';
        $query('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))', [$email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $db->lastInsertId();
        $accounts[$role->value] = compact('id', 'email');
        $query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $role->value]);
        if ($role === Role::Teacher) $query("INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone, terms_version, privacy_version, accepted_at, sections, verification_status, professional_verified_at) VALUES (?, 'teacher', 'Synthetic', 'Teacher', '+2348012345678', 'fixture', 'fixture', UTC_TIMESTAMP(6), JSON_OBJECT(), 'verified', UTC_TIMESTAMP(6))", [$id]);
        if ($role === Role::School) $query("INSERT INTO school_onboarding (user_id, school_name, contact_phone, review_status, approved_at) VALUES (?, 'Synthetic Settings School', '+2348012345678', 'approved', UTC_TIMESTAMP(6))", [$id]);
        if ($role === Role::Parent) $query("INSERT INTO parent_profiles (user_id, first_name, last_name, phone, state_code, city, terms_version, privacy_version, accepted_at) VALUES (?, 'Synthetic', 'Parent', '+2348012345678', 'LA', 'Ikeja', 'fixture', 'fixture', UTC_TIMESTAMP(6))", [$id]);
        if ($role === Role::Admin) $query("INSERT INTO staff_user_roles (user_id, role_id) SELECT ?, id FROM staff_roles WHERE slug = 'super_admin'", [$id]);
    }
    $settingsService = new Furoeducare\Modules\Settings\AccountSettings(static fn (): PDO => $db, $config);
    foreach ($accounts as $account) $settingsService->read($account['id']);
    serve($root, ['APP_ENV' => 'development', 'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail',
        'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log', static function (string $base) use ($query, $db, $accounts, $password, $changedPassword, $work): void {
        $browsers = [];
        $anonymous = new AuthBrowser($base);
        redirectIs($anonymous->send('/settings/account'), '/login');
        foreach (Role::cases() as $role) {
            $browser = new AuthBrowser($base);
            redirectIs($browser->submit('/login', ['email' => $accounts[$role->value]['email'], 'password' => $password]), $role->dashboard());
            $browsers[$role->value] = $browser;
            [$code, $headers, $html] = $browser->send('/settings/account');
            file_put_contents($work . '/' . $role->value . '-settings.html', $html);
            expect($code === 200 && str_contains($html, $accounts[$role->value]['email']), 'Every role sees its own account.');
            expect(str_contains($headers['cache-control'] ?? '', 'no-store') && ($headers['referrer-policy'] ?? '') === 'no-referrer', 'Sensitive settings are not cached or referred.');
            expect(str_contains($html, '/settings/notifications') && str_contains($html, '/privacy/controls'), 'Existing notification and privacy controls remain linked.');
            settingsStatus($browser->send('/settings/account', 'POST', ['action' => 'appearance', 'appearance' => 'dark']), 403, 'CSRF required for every role.');
            settingsStatus($browser->submit('/settings/account', ['action' => 'appearance', 'appearance' => 'dark', 'user_id' => $accounts['parent']['id']]), 422, 'Owner injection rejected.');
            settingsStatus($browser->submit('/settings/account', ['action' => 'unknown']), 422, 'Unknown action rejected.');
        }
        foreach (['/' => 'home', '/login' => 'login', '/register' => 'register', '/digital-library' => 'library', '/messages' => 'messages'] as $path => $name) {
            $browser = in_array($name, ['login', 'register'], true) ? $anonymous : $browsers['parent'];
            [$status, , $html] = $browser->send($path);
            expect($status === 200, 'Preview fixture route loads: ' . $path);
            file_put_contents($work . '/' . $name . '.html', $html);
        }
        echo "PASS four-role own settings, CSRF, strict fields and related controls.\n";
        $teacher = $browsers['teacher']; $parent = $browsers['parent']; $school = $browsers['school']; $admin = $browsers['admin'];
        redirectIs($teacher->submit('/settings/account', ['action' => 'appearance', 'appearance' => 'light', 'return_to' => '/digital-library']), '/digital-library');
        redirectIs($teacher->submit('/settings/account', ['action' => 'appearance', 'appearance' => 'system', 'return_to' => '//evil.example']), '/settings/account');
        foreach (['dark', 'light', 'system'] as $appearance) {
            redirectIs($teacher->submit('/settings/account', ['action' => 'appearance', 'appearance' => $appearance]), '/settings/account');
            expect($query('SELECT appearance FROM account_preferences WHERE user_id = ?', [$accounts['teacher']['id']])->fetchColumn() === $appearance, 'Each theme persists.');
            $html = $teacher->send('/')[2];
            expect(str_contains($html, 'data-account-appearance="' . $appearance . '"') && str_contains($html, 'data-bs-theme="' . ($appearance === 'dark' ? 'dark' : 'light') . '"'), 'Authenticated public pages use the server account theme.');
            expect(str_contains($parent->send('/settings/account')[2], 'data-account-appearance="system"'), 'Theme changes do not leak across accounts.');
        }
        expect(!str_contains($anonymous->send('/')[2], 'data-account-appearance='), 'Anonymous page has no account override.');
        settingsStatus($teacher->submit('/settings/account', ['action' => 'appearance', 'appearance' => 'invalid']), 422, 'Unsupported theme refused.');
        echo "PASS three appearance choices, account isolation and public server attributes.\n";
        foreach (['teacher', 'school', 'parent', 'admin'] as $role) {
            redirectIs($browsers[$role]->submit('/settings/account', ['action' => 'phone', 'current_password' => $password, 'profile_role' => $role, 'phone' => '+2348098765432']), '/settings/account');
        }
        expect($query('SELECT verification_status FROM teacher_profiles WHERE user_id = ?', [$accounts['teacher']['id']])->fetchColumn() === 'pending', 'Teacher phone change reopens professional review.');
        expect($query('SELECT review_status FROM school_onboarding WHERE user_id = ?', [$accounts['school']['id']])->fetchColumn() === 'pending', 'School phone change reopens approval.');
        settingsStatus($parent->submit('/settings/account', ['action' => 'phone', 'current_password' => $password, 'profile_role' => 'teacher', 'phone' => '+2348011111111']), 403, 'Cannot update an unowned role phone.');
        settingsStatus($parent->submit('/settings/account', ['action' => 'admin_profile', 'current_password' => $password, 'display_name' => 'Injected staff']), 403, 'Non-staff cannot edit staff details.');
        redirectIs($admin->submit('/settings/account', ['action' => 'admin_profile', 'current_password' => $password, 'display_name' => 'Synthetic <Staff>']), '/settings/account');
        expect(str_contains($admin->send('/settings/account')[2], 'Synthetic &lt;Staff&gt;'), 'Staff display name is escaped.');
        expect($query('SELECT COUNT(*) FROM users')->fetchColumn() === 4, 'Settings preserve the one-account model.');
        echo "PASS role-owned contact edits, review reopening and restricted escaped staff name.\n";

        $id = $accounts['parent']['id'];
        $second = new AuthBrowser($base);
        redirectIs($second->submit('/login', ['email' => $accounts['parent']['email'], 'password' => $password]), '/dashboard/parent');
        $query('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP(6) + INTERVAL 30 MINUTE)', [$id, random_bytes(32)]);
        settingsStatus($parent->submit('/settings/account', ['action' => 'password', 'current_password' => 'wrong', 'password' => $changedPassword, 'password_confirmation' => $changedPassword]), 422, 'Password reauthentication required.');
        $oldCookie = $parent->cookie;
        redirectIs($parent->submit('/settings/account', ['action' => 'password', 'current_password' => $password, 'password' => $changedPassword, 'password_confirmation' => $changedPassword]), '/settings/account');
        expect($oldCookie !== $parent->cookie && $parent->send('/settings/account')[0] === 200, 'Password change rotates and renews the current session.');
        $record = $query('SELECT password_hash, auth_version FROM users WHERE id = ?', [$id])->fetch();
        expect(password_verify($changedPassword, $record['password_hash']) && !password_verify($password, $record['password_hash']) && (int) $record['auth_version'] === 2, 'New password is hashed and increments auth version.');
        redirectIs($second->send('/settings/account'), '/login');
        expect((int) $query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ? AND consumed_at IS NULL', [$id])->fetchColumn() === 0, 'Password change invalidates outstanding reset links.');
        echo "PASS password reauthentication, hash, session rotation and reset/session invalidation.\n";

        $limits = new RateLimiter(static fn (): PDO => $db);
        $clear = static function () use ($limits, $id): void {
            foreach (['email', 'confirm_email', 'cancel_email', 'password', 'deactivate', 'cancel_deactivation'] as $action) $limits->clear('settings-action-' . $action, (string) $id);
            $limits->clear('settings-account', (string) $id);
            $limits->clear('settings-network', RateLimiter::network('127.0.0.1'));
        };
        $issue = static function (string $email) use ($parent, $changedPassword, $query, $id, $work): array {
            redirectIs($parent->submit('/settings/account', ['action' => 'email', 'current_password' => $changedPassword, 'new_email' => $email]), '/settings/account');
            $row = $query('SELECT * FROM email_outbox WHERE user_id = ? AND template = ? ORDER BY id DESC LIMIT 1', [$id, 'email_change'])->fetch();
            expect($row !== false && $row['status'] === 'pending' && $row['secret_payload'] !== null && json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR) === [], 'Email verification is queued with an encrypted secret.');
            [$token] = tokenFile($work . '/mail', $email, '/settings/email-change');
            expect(!str_contains($row['secret_payload'], $token), 'Queued secret does not expose the raw token.');
            $delivered = $query('SELECT status, secret_payload FROM email_outbox WHERE id = ?', [$row['id']])->fetch();
            expect($delivered['status'] === 'accepted' && $delivered['secret_payload'] === null, 'Real file worker delivers and erases encrypted token.');
            return [$token, '/settings/email-change?token=' . $token];
        };
        settingsStatus($parent->submit('/settings/account', ['action' => 'email', 'current_password' => $changedPassword, 'new_email' => $accounts['teacher']['email']]), 422, 'Duplicate address at issue refused.');
        redirectIs($parent->submit('/settings/notifications', ['enabled' => []]), '/settings/notifications');
        [$token, $path] = $issue('settings-parent-new@example.test');
        expect($query('SELECT email FROM users WHERE id = ?', [$id])->fetchColumn() === $accounts['parent']['email'], 'Issuing verification retains the old sign-in address.');
        settingsStatus($parent->send($path), 200, 'GET previews challenge.');
        expect($query('SELECT consumed_at FROM account_email_changes WHERE token_hash = ?', [hash('sha256', $token, true)])->fetchColumn() === null, 'GET never consumes challenge.');
        settingsStatus($teacher->send($path), 410, 'Another account cannot preview challenge.');
        settingsStatus($teacher->submit('/settings/email-change', ['token' => $token, 'current_password' => $password], '/settings/account'), 410, 'Another account cannot confirm challenge.');
        settingsStatus($parent->send('/settings/email-change', 'POST', ['token' => $token, 'current_password' => $changedPassword]), 403, 'Email confirmation needs CSRF.');
        settingsStatus($parent->submit('/settings/email-change', ['token' => $token, 'current_password' => 'wrong'], $path), 422, 'Email confirmation reauthenticates.');
        $query('UPDATE users SET email = ? WHERE id = ?', ['settings-parent-new@example.test', $accounts['school']['id']]);
        settingsStatus($parent->submit('/settings/email-change', ['token' => $token, 'current_password' => $changedPassword], $path), 422, 'Duplicate claimed after issue cannot be confirmed.');
        $query('UPDATE users SET email = ? WHERE id = ?', [$accounts['school']['email'], $accounts['school']['id']]);
        $continuation = new AuthBrowser($base);
        redirectIs($continuation->send($path), '/login');
        redirectIs($continuation->submit('/login', ['email' => $accounts['parent']['email'], 'password' => $changedPassword]), $path);
        $oldCookie = $parent->cookie;
        redirectIs($parent->submit('/settings/email-change', ['token' => $token, 'current_password' => $changedPassword], $path), '/settings/account');
        expect($oldCookie !== $parent->cookie && $parent->send('/settings/account')[0] === 200, 'Email confirmation renews current session.');
        redirectIs($continuation->send('/settings/account'), '/login');
        expect($query('SELECT email FROM users WHERE id = ?', [$id])->fetchColumn() === 'settings-parent-new@example.test', 'Only authenticated POST confirms new address.');
        settingsStatus($parent->send($path), 410, 'Consumed email link cannot replay.');
        echo "PASS encrypted email worker, unchanged GET, ownership, reauth, duplicate races and login continuation.\n";
        $clear();
        [$cancelToken, $cancelPath] = $issue('settings-cancel@example.test');
        redirectIs($parent->submit('/settings/account', ['action' => 'cancel_email', 'current_password' => $changedPassword]), '/settings/account');
        settingsStatus($parent->send($cancelPath), 410, 'Cancelled link is invalid.');
        [$expiredToken, $expiredPath] = $issue('settings-expired@example.test');
        $query('UPDATE account_email_changes SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE token_hash = ?', [hash('sha256', $expiredToken, true)]);
        settingsStatus($parent->send($expiredPath), 410, 'Expired link is invalid.');
        [$oldToken, $oldPath] = $issue('settings-superseded@example.test');
        [$boundToken, $boundPath] = $issue('settings-bound@example.test');
        settingsStatus($parent->send($oldPath), 410, 'Superseded link is invalid.');
        redirectIs($parent->submit('/settings/account', ['action' => 'password', 'current_password' => $changedPassword, 'password' => $password, 'password_confirmation' => $password]), '/settings/account');
        settingsStatus($parent->send($boundPath), 410, 'Password version change invalidates email link.');
        echo "PASS cancelled, expired, superseded, consumed and password-bound links.\n";
        $clear();
        redirectIs($parent->submit('/settings/account', ['action' => 'email', 'current_password' => $password, 'new_email' => 'settings-undelivered@example.test']), '/settings/account');
        $queued = $query("SELECT id FROM email_outbox WHERE user_id = ? AND template = 'email_change' ORDER BY id DESC LIMIT 1", [$id])->fetchColumn();
        redirectIs($parent->submit('/settings/account', ['action' => 'cancel_email', 'current_password' => $password]), '/settings/account');
        drainTestMail($work . '/mail');
        $suppressed = $query('SELECT status, secret_payload FROM email_outbox WHERE id = ?', [$queued])->fetch();
        expect($suppressed['status'] === 'suppressed' && $suppressed['secret_payload'] === null, 'Worker suppresses cancelled pending email and erases its secret.');
        foreach ([$parent, $admin] as $browser) {
            $who = $browser === $parent ? $id : $accounts['admin']['id'];
            settingsStatus($browser->submit('/settings/account', ['action' => 'deactivate', 'current_password' => $password, 'deactivation_reason' => 'Synthetic review request']), 422, 'Explicit deactivation confirmation required.');
            settingsStatus($browser->submit('/settings/account', ['action' => 'deactivate', 'current_password' => 'wrong', 'confirmed' => '1', 'deactivation_reason' => 'Synthetic review request']), 422, 'Deactivation reauthenticates.');
            redirectIs($browser->submit('/settings/account', ['action' => 'deactivate', 'current_password' => $password, 'confirmed' => '1', 'deactivation_reason' => 'Synthetic review request']), '/settings/account');
            settingsStatus($browser->submit('/settings/account', ['action' => 'deactivate', 'current_password' => $password, 'confirmed' => '1', 'deactivation_reason' => 'Duplicate']), 409, 'Duplicate deactivation refused.');
            $request = $query('SELECT d.*, p.right_type, p.status AS privacy_status FROM account_deactivation_requests d JOIN candidate_privacy_requests p ON p.id = d.privacy_request_id WHERE d.user_id = ?', [$who])->fetch();
            expect($request['right_type'] === 'restriction' && $request['privacy_status'] === 'submitted', 'Deactivation enters existing privacy management queue.');
            expect($query('SELECT suspended_at FROM users WHERE id = ?', [$who])->fetchColumn() === null, 'Request never automatically suspends account, including sole Super Admin.');
            redirectIs($browser->submit('/settings/account', ['action' => 'cancel_deactivation', 'current_password' => $password]), '/settings/account');
            expect($query('SELECT status FROM account_deactivation_requests WHERE id = ?', [$request['id']])->fetchColumn() === 'cancelled'
                && $query('SELECT status FROM candidate_privacy_requests WHERE id = ?', [$request['privacy_request_id']])->fetchColumn() === 'resolved', 'Cancellation resolves linked privacy request.');
        }
        $clear();
        $query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'school'", [$id]);
        $query("INSERT INTO school_onboarding (user_id, school_name, review_status) VALUES (?, 'Synthetic second role', 'pending')", [$id]);
        redirectIs($parent->submit('/settings/account', ['action' => 'phone', 'current_password' => $password, 'profile_role' => 'school', 'phone' => '+2348012222222']), '/settings/account');
        expect($query('SELECT contact_phone FROM school_onboarding WHERE user_id = ?', [$id])->fetchColumn() === '+2348012222222'
            && (int) $query('SELECT COUNT(*) FROM users')->fetchColumn() === 4, 'One account owns both parent and school contacts without another identity.');
        redirectIs($parent->submit('/settings/account', ['action' => 'deactivate', 'current_password' => $password, 'confirmed' => '1', 'deactivation_reason' => 'Review status fixture']), '/settings/account');
        $query("UPDATE candidate_privacy_requests p JOIN account_deactivation_requests d ON d.privacy_request_id = p.id SET p.status = 'resolved', p.closed_by = ?, p.closed_at = UTC_TIMESTAMP(6) WHERE d.user_id = ? AND d.status = 'pending'", [$accounts['admin']['id'], $id]);
        expect(str_contains($parent->send('/settings/account')[2], '>Reviewed</span>'), 'Existing privacy management review appears in account settings.');
        settingsStatus($parent->submit('/settings/account', ['action' => 'cancel_deactivation', 'current_password' => $password]), 409, 'An already reviewed request cannot be cancelled.');
        $clear();
        for ($attempt = 0; $attempt < 10; $attempt++) settingsStatus($parent->submit('/settings/account', ['action' => 'cancel_email', 'current_password' => 'wrong']), 422, 'Failed sensitive attempts counted.');
        settingsStatus($parent->submit('/settings/account', ['action' => 'cancel_email', 'current_password' => $password]), 429, 'Sensitive action is rate limited.');
        echo "PASS deactivation consent, reauth, privacy queue, cancellation, sole-admin preservation and rate limits.\n";
    });
    echo 'Settings artifacts: ' . $work . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

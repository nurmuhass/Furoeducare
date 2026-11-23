<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';

require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/school-fixtures.php';

function expireSession(string $directory, string $cookie, string $clock, int $seconds): void
{
    expect(preg_match('/\AFUROEDUCARE_SESSION=([a-zA-Z0-9,-]+)\z/', $cookie, $match) === 1, 'No session cookie to expire.');
    $path = $directory . '/sess_' . $match[1];
    $previous = file_get_contents($path);
    $next = preg_replace('/"' . $clock . '";i:\d+;/', '"' . $clock . '";i:' . (time() - $seconds) . ';', $previous, 1, $count);
    expect($count === 1 && file_put_contents($path, $next, LOCK_EX) !== false, "Cannot set the $clock time in the test session.");
}

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-auth-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$mail = $work . '/mail';
$sessions = $work . '/sessions';
mkdir($mail, 0700);
mkdir($sessions, 0700);
$serverLog = $work . '/server.log';
$errorLog = $work . '/app.log';
$exit = 0;

try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_auth_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback development database named furoeducare_test_auth_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'The authentication test database must be empty.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $seed = new SeedRunner($db, $root . '/database/seeders');
    $migrations->withCurrentSchema($seed->run(...));

    $environment = [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000', 'AUTH_SESSION_DIR' => $sessions,
        'AUTH_MAIL_DIR' => $mail, 'APP_LOG_FILE' => $errorLog,
    ];

    serve($root, $environment, $serverLog, function (string $base) use ($db, $mail, $sessions): void {
        $teacher = new AuthBrowser($base);
        $password = 'Fixture education password 26!';
        $newPassword = 'Fixture replacement password 97!';
        $email = 'teacher@example.test';
        $creds = ['email' => $email, 'password' => $password];
        redirectIs($teacher->send('/dashboard/admin'), '/login');
        expect($teacher->send('/login', 'POST', $creds)[0] === 403, 'Login must require CSRF.');
        expect($teacher->send('/logout')[0] === 405, 'GET must not log out an account.');
        expect($teacher->submit('/register', ['email' => 'bad'])[0] === 422, 'Server-side registration validation must reject invalid email.');
        $unsolicitedPassword = 'Unsolicited registration password!';
        redirectIs($teacher->submit('/register', ['email' => $email, 'role' => 'admin', 'password' => $unsolicitedPassword]), '/login');
        [$first, $firstFile] = tokenFile($mail, $email, '/verify-email');
        expect($teacher->submit('/login', $creds)[0] === 401, 'A pending account must have no usable password.');

        $duplicate = new AuthBrowser($base);
        redirectIs($duplicate->submit('/register', ['email' => strtoupper($email), 'role' => 'parent']), '/login');
        expect((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Duplicate email must keep one user.');
        expect((int) $db->query('SELECT COUNT(*) FROM user_roles')->fetchColumn() === 0, 'Unverified registration must not assign a role.');
        expect(count(glob($mail . '/*.eml')) === 1, 'Duplicate registration must not send another verification message.');

        redirectIs($teacher->submit('/resend-verification', ['email' => $email]), '/resend-verification');
        [$newLink] = tokenFile($mail, $email, '/verify-email', [$firstFile]);
        $verification = '/verify-email?token=' . $first;
        $csrf = $teacher->form($verification);
        expect($db->query('SELECT email_verified_at FROM users')->fetchColumn() === null, 'Viewing an email link must not verify an account.');
        $verifyFields = ['_csrf' => $csrf, 'token' => $first, 'password' => $password, 'password_confirmation' => $password];
        expect($teacher->send('/verify-email', 'POST', $verifyFields + ['role' => 'admin'])[0] === 422, 'Public account verification must reject admin.');
        $teacherLink = '/verify-teacher?token=' . $first;
        redirectIs($teacher->send('/verify-email', 'POST', $verifyFields + ['role' => 'teacher']), $teacherLink);
        $teacherFields = $verifyFields + [
            'category' => 'teacher', 'first_name' => 'Fixture', 'last_name' => 'Teacher',
            'phone' => '08012345678', 'email' => $email, 'terms' => '1', 'privacy' => '1',
        ];
        expect($teacher->send('/verify-teacher', 'POST', array_replace($teacherFields, ['password' => 'short']))[0] === 422, 'Verification must validate the password.');
        redirectIs($teacher->send('/verify-teacher', 'POST', $teacherFields), '/dashboard/teacher');
        redirectIs($teacher->submit('/logout', [], '/dashboard/teacher'), '/login');
        $teacherFields['_csrf'] = $teacher->form('/login');
        expect($teacher->send('/verify-teacher', 'POST', $teacherFields)[0] === 422, 'Verification links must be single-use.');
        expect($teacher->send('/verify-teacher', 'POST', array_replace($teacherFields, ['token' => $newLink]))[0] === 422, 'Successful verification must revoke other links.');
        expect($db->query('SELECT roles.slug FROM roles JOIN user_roles ON user_roles.role_id = roles.id')->fetchColumn() === 'teacher', 'The mailbox owner chooses the account role.');
        expect($teacher->submit('/login', ['email' => $email, 'password' => $unsolicitedPassword])[0] === 401, 'An unsolicited registration cannot set the verified password.');
        echo "PASS registration, duplicate accounts, role and password validation, CSRF, and mailbox-owned activation.\n";

        $anonymousCookie = $teacher->cookie;
        expect($teacher->submit('/login', ['email' => $email, 'password' => 'Incorrect test password'])[0] === 401, 'An incorrect password must fail.');
        redirectIs($teacher->submit('/login', $creds), '/dashboard/teacher');
        expect($teacher->cookie !== $anonymousCookie, 'Authentication must regenerate the session ID.');
        $stale = new AuthBrowser($base);
        $stale->cookie = $anonymousCookie;
        redirectIs($stale->send('/dashboard'), '/login');
        expect($teacher->send('/dashboard/school')[0] === 403 && $teacher->send('/dashboard/admin')[0] === 403, 'A teacher must not access school or admin routes.');
        expect($teacher->send('/logout', 'POST', ['_csrf' => 'bad'])[0] === 403, 'Logout must require CSRF.');
        $signedIn = $teacher->cookie;
        redirectIs($teacher->submit('/logout', [], '/dashboard/teacher'), '/login');
        $stale->cookie = $signedIn;
        redirectIs($stale->send('/dashboard'), '/login');
        redirectIs($teacher->submit('/login', $creds), '/dashboard/teacher');
        echo "PASS incorrect passwords, role protection, login regeneration and logout invalidation.\n";

        $school = new AuthBrowser($base);
        redirectIs($school->submit('/register', ['email' => 'school@example.test']), '/login');
        [$schoolToken] = tokenFile($mail, 'school@example.test', '/verify-email');
        $schoolForm = '/verify-school?token=' . $schoolToken;
        redirectIs($school->submit('/verify-email', ['token' => $schoolToken, 'role' => 'school'], '/verify-email?token=' . $schoolToken), $schoolForm);
        expect($school->submit('/verify-school', ['token' => $schoolToken], $schoolForm)[0] === 422, 'School onboarding requires a complete institutional profile.');
        redirectIs($school->submit('/verify-school', ['token' => $schoolToken] + schoolFields('school@example.test', $password), $schoolForm), '/dashboard/school');
        [$schoolStatus, , $pending] = $school->send('/dashboard/school');
        expect($schoolStatus === 200 && str_contains($pending, 'Pending approval')
            && !str_contains($pending, 'Your school is approved to submit vacancies'), 'Authentication must never approve a school.');
        expect($db->query('SELECT approved_at FROM school_onboarding')->fetchColumn() === null, 'New school approval must be null.');
        $db->exec("UPDATE school_onboarding SET approved_at = CURRENT_TIMESTAMP(6), review_status = 'approved'");
        expect(str_contains($school->send('/dashboard/school')[2], 'Your school is approved to submit vacancies'), 'School dashboard must read the approval record.');

        $parent = new AuthBrowser($base);
        verifiedParent($parent, $mail, 'parent@example.test', $password);
        redirectIs($parent->submit('/login', ['email' => 'parent@example.test', 'password' => $password]), '/dashboard/parent');
        expect($parent->send('/dashboard/teacher')[0] === 403, 'A parent must not enter a teacher dashboard.');
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
            ->execute(['admin@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $adminId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin'")->execute([$adminId]);
        grantTestStaffRole($db, $adminId);
        $admin = new AuthBrowser($base);
        redirectIs($admin->submit('/login', ['email' => 'admin@example.test', 'password' => $password]), '/dashboard/admin');
        expect($admin->send('/dashboard/admin')[0] === 200, 'An assigned admin role must allow its dashboard.');
        echo "PASS teacher, school, parent and admin redirects and independent school approval.\n";

        $otherSession = new AuthBrowser($base);
        redirectIs($otherSession->submit('/login', $creds), '/dashboard/teacher');
        $reset = new AuthBrowser($base);
        redirectIs($reset->submit('/forgot-password', ['email' => 'missing@example.test']), '/forgot-password');
        [, , $missingMessage] = $reset->send('/forgot-password');
        redirectIs($reset->submit('/forgot-password', ['email' => $email]), '/forgot-password');
        [, , $knownMessage] = $reset->send('/forgot-password');
        expect(str_contains($missingMessage, 'If this address is eligible') && str_contains($knownMessage, 'If this address is eligible'), 'Reset requests must use an account-neutral message.');
        [$expired, $oldFile] = tokenFile($mail, $email, '/reset-password');
        $db->prepare('UPDATE password_reset_tokens SET expires_at = CURRENT_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE token_hash = ?')
            ->execute([hash('sha256', $expired, true)]);
        $resetFields = ['password' => $newPassword, 'password_confirmation' => $newPassword];
        expect($reset->submit('/reset-password', $resetFields + ['token' => $expired], '/reset-password?token=' . $expired)[0] === 422, 'Expired reset tokens must fail.');
        redirectIs($reset->submit('/forgot-password', ['email' => $email]), '/forgot-password');
        [$fresh, $freshFile] = tokenFile($mail, $email, '/reset-password', [$oldFile]);
        redirectIs($reset->submit('/forgot-password', ['email' => $email]), '/forgot-password');
        [$newer] = tokenFile($mail, $email, '/reset-password', [$oldFile, $freshFile]);
        $form = '/reset-password?token=' . $fresh;
        $rowBefore = $db->query("SELECT password_hash FROM users WHERE email = 'teacher@example.test'")->fetchColumn();
        $reset->form($form);
        expect($db->query("SELECT password_hash FROM users WHERE email = 'teacher@example.test'")->fetchColumn() === $rowBefore, 'GET must never reset a password.');
        redirectIs($reset->submit('/reset-password', $resetFields + ['token' => $fresh], $form), '/login');
        expect($reset->submit('/reset-password', $resetFields + ['token' => $fresh], $form)[0] === 422, 'Reset tokens must be single-use.');
        expect($reset->submit('/reset-password', $resetFields + ['token' => $newer], '/reset-password?token=' . $newer)[0] === 422, 'A successful reset must revoke all remaining links.');
        redirectIs($teacher->send('/dashboard'), '/login');
        redirectIs($otherSession->send('/dashboard'), '/login');
        expect($teacher->submit('/login', $creds)[0] === 401, 'Old password must fail after reset.');
        $newCredentials = ['email' => $email, 'password' => $newPassword];
        redirectIs($teacher->submit('/login', $newCredentials), '/dashboard/teacher');
        echo "PASS account-neutral reset requests, token expiry and reuse, and revocation of all prior sessions.\n";

        expireSession($sessions, $teacher->cookie, 'seen', 1900);
        redirectIs($teacher->send('/dashboard'), '/login');
        redirectIs($teacher->submit('/login', $newCredentials), '/dashboard/teacher');
        expireSession($sessions, $teacher->cookie, 'issued', 29000);
        redirectIs($teacher->send('/dashboard'), '/login');
        redirectIs($teacher->submit('/login', $newCredentials), '/dashboard/teacher');
        $unchecked = new AuthBrowser($base);
        redirectIs($unchecked->submit('/login', $newCredentials), '/dashboard/teacher');
        $db->exec("UPDATE users SET suspended_at = CURRENT_TIMESTAMP(6), auth_version = auth_version + 1 WHERE email = 'teacher@example.test'");
        redirectIs($teacher->send('/dashboard'), '/login');
        [$blocked, , $blockedBody] = $teacher->submit('/login', $newCredentials);
        expect($blocked === 403 && str_contains($blockedBody, 'This account is suspended'), 'Suspended accounts must not sign in.');

        $db->exec("UPDATE users SET suspended_at = NULL WHERE email = 'teacher@example.test'");
        redirectIs($unchecked->send('/dashboard'), '/login');

        $attempt = new AuthBrowser($base);
        $bad = ['email' => 'rate-limit@example.test', 'password' => 'Incorrect test password'];
        for ($i = 0; $i < 5; $i++) {
            expect($attempt->submit('/login', $bad)[0] === 401, 'Allowed failed attempt must return HTTP 401.');
        }
        [$limited, $limits] = $attempt->submit('/login', $bad);
        expect($limited === 429 && (int) ($limits['retry-after'] ?? 0) > 0, 'Excessive attempts must receive HTTP 429 and Retry-After.');
        $accountKey = hash('sha256', "login-account\0distributed@example.test", true);
        $db->prepare('INSERT INTO auth_rate_limits (bucket, attempts, window_started_at) VALUES (?, 20, CURRENT_TIMESTAMP(6))')
            ->execute([$accountKey]);
        expect($attempt->submit('/login', ['email' => 'distributed@example.test', 'password' => $bad['password']])[0] === 429,
            'A saturated account-wide bucket must block a fresh account-and-network bucket.');
        echo "PASS idle and absolute session expiry, suspended sessions and login limits.\n";
    });

    serve($root, array_replace($environment, ['APP_ENV' => 'production', 'APP_URL' => 'https://example.test']), $serverLog, static function (string $base): void {
        [$status, $headers] = request($base, '/login');
        $cookie = strtolower($headers['set-cookie'] ?? '');
        expect($status === 200 && str_contains($cookie, '; secure') && str_contains($cookie, '; httponly')
            && str_contains($cookie, 'samesite=lax'), 'Production cookies must have Secure, HttpOnly and SameSite=Lax.');
        expect(($headers['cache-control'] ?? '') === 'no-store' && ($headers['referrer-policy'] ?? '') === 'no-referrer',
            'Authentication pages must prohibit caching and referrer disclosure.');
    });
    echo "PASS production cookie and authentication response headers.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    if (is_file($errorLog)) {
        fwrite(STDERR, file_get_contents($errorLog));
    }
    if (is_file($serverLog)) {
        fwrite(STDERR, implode("", array_slice(file($serverLog), -18)));
    }
    $exit = 1;
} finally {
    foreach ([$mail, $sessions] as $directory) {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
    foreach ([$serverLog, $errorLog] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($work);
}
exit($exit);

<?php
declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\AuthService;
use Furoeducare\Modules\Auth\EmailToken;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Email\EmailWorker;
use Furoeducare\Modules\Email\MailCrypto;
use Furoeducare\Modules\Settings\AccountSettings;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';

$root = dirname(__DIR__);
$suffix = bin2hex(random_bytes(6));
$work = $root . '/storage/local-preview/security-auth-' . $suffix;
mkdir($work, 0700, true);
putenv('MAIL_TRANSPORT=file');
putenv('MAIL_FROM=security@example.test');
putenv('MAIL_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
putenv('AUTH_MAIL_DIR=' . $work . '/mail');
$config = Config::load($root);
expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
    && preg_match('/\Afuroeducare_test_(?:admin|settings)_[a-f0-9]{12}\z/', $config->databaseName) === 1,
    'Security auth tests require an isolated loopback test schema.');
$db = Database::connect($config);
$query = static function (string $sql, array $values = []) use ($db): PDOStatement {
    $q = $db->prepare($sql); $q->execute($values); return $q;
};
$auth = new AuthService(static fn (): PDO => $db);
$settings = new AccountSettings(static fn (): PDO => $db, $config);
$ids = [];
$password = 'Security original fixture 461!';
$replacement = 'Security replacement fixture 462!';
$email = 'security-' . $suffix . '@example.test';
try {
    $verification = $auth->register($email);
    $ids[] = $id = (int) $auth->findUser($email)['id'];
    expect($auth->register(strtoupper($email)) === null, 'Case variants cannot create duplicate accounts.');
    expect($auth->canConfirmProfileEmail($verification), 'Fresh verification token is active.');
    expect($auth->confirmProfileEmail(Role::Parent, $verification, 'different@example.test', static fn (): string => password_hash($password, PASSWORD_DEFAULT), static fn () => null) === null,
        'Verification token is bound to the account email.');
    $query('UPDATE email_verification_tokens SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE user_id = ?', [$id]);
    expect(!$auth->canConfirmProfileEmail($verification), 'Expired verification cannot be opened.');
    expect($auth->confirmProfileEmail(Role::Parent, $verification, $email, static fn (): string => password_hash($password, PASSWORD_DEFAULT), static fn () => null) === null,
        'Expired verification cannot be consumed.');
    $verification = $auth->requestLink(EmailToken::Verification, $email);
    $activated = $auth->confirmProfileEmail(Role::Parent, $verification, $email, static fn (): string => password_hash($password, PASSWORD_DEFAULT), static fn () => null);
    expect($activated !== null && !$auth->canConfirmProfileEmail($verification), 'Verification activates once.');
    $version = $activated['version'];
    $reset = $auth->requestLink(EmailToken::PasswordReset, $email);
    $reset2 = $auth->requestLink(EmailToken::PasswordReset, $email);
    $settings->requestEmail($id, $version, $password, 'security-new-' . $suffix . '@example.test');
    $outbox = $query("SELECT * FROM email_outbox WHERE user_id = ? AND template = 'email_change' ORDER BY id DESC LIMIT 1", [$id])->fetch();
    $change = MailCrypto::decrypt($outbox['secret_payload'], $config->mailOptions['encryption_key']);
    expect(!str_contains($outbox['secret_payload'], $change), 'Email challenge is encrypted at rest.');
    expect($settings->emailChallenge($id, $version, $change) !== null, 'Owner can review new email.');
    $worker = new EmailWorker(static fn (): PDO => $db, $config);
    $eligible = new ReflectionMethod($worker, 'eligible');
    expect($eligible->invoke($worker, $outbox), 'Fresh email challenge is deliverable.');

    serve($root, ['APP_ENV' => 'development', 'AUTH_SESSION_DIR' => $work . '/sessions',
        'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log',
        static function (string $base) use ($auth, $settings, $worker, $eligible, $outbox, $query, $id, $email, $version, $password, $replacement, $reset, $reset2, $change, $work): void {
            $owner = new AuthBrowser($base);
            $anonymous = $owner->form('/login');
            $oldCookie = $owner->cookie;
            expect($owner->send('/login', 'POST', ['email' => $email, 'password' => $password])[0] === 403, 'Login rejects absent CSRF.');
            redirectIs($owner->send('/login', 'POST', ['email' => $email, 'password' => $password, '_csrf' => $anonymous]), '/dashboard/parent');
            expect($owner->cookie !== $oldCookie, 'Login rotates the session cookie.');
            $intruder = new AuthBrowser($base); $intruder->cookie = $oldCookie;
            redirectIs($intruder->send('/dashboard'), '/login');
            $account = $owner->send('/settings/account');
            expect($account[0] === 200 && str_contains($account[1]['cache-control'] ?? '', 'no-store')
                && ($account[1]['referrer-policy'] ?? '') === 'no-referrer', 'Settings protect cache and referrer.');
            expect($owner->send('/logout', 'POST', ['_csrf' => 'bad'])[0] === 403, 'Logout rejects forged CSRF.');
            $csrf = $owner->form('/settings/account');
            $body = ['_csrf' => $csrf, 'action' => 'password', 'current_password' => 'wrong', 'password' => $replacement, 'password_confirmation' => $replacement];
            expect($owner->send('/settings/account', 'POST', $body)[0] === 422, 'Password change requires current password.');
            expect($owner->send('/settings/email-change?token=' . $change)[0] === 200, 'Email GET reviews challenge.');
            expect($query('SELECT consumed_at FROM account_email_changes WHERE token_hash = ?', [hash('sha256', $change, true)])->fetchColumn() === null,
                'GET does not consume the email challenge.');
            $query('UPDATE password_reset_tokens SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE token_hash = ?', [hash('sha256', $reset, true)]);
            expect(!$auth->resetPassword($reset, $replacement), 'Expired reset token cannot change credentials.');
            expect($auth->resetPassword($reset2, $replacement), 'Fresh reset token changes password.');
            expect(!$auth->resetPassword($reset2, $password), 'Reset token cannot be replayed.');
            expect(!$auth->checkPassword($email, $password) && $auth->checkPassword($email, $replacement) !== null, 'Old password fails after reset.');
            expect($settings->emailChallenge($id, $version, $change) === null && !$eligible->invoke($worker, $outbox), 'Reset invalidates in-flight email change and delivery.');
            redirectIs($owner->send('/settings/account'), '/login');
            redirectIs($owner->submit('/login', ['email' => $email, 'password' => $replacement]), '/dashboard/parent');
            foreach (['seen' => 1900, 'issued' => 29000] as $clock => $seconds) {
                expect(preg_match('/\AFUROEDUCARE_SESSION=([a-zA-Z0-9,-]+)\z/', $owner->cookie, $match) === 1, 'Session cookie is available.');
                $sessionFile = $work . '/sessions/sess_' . $match[1];
                $session = preg_replace('/"' . $clock . '";i:\d+;/', '"' . $clock . '";i:' . (time() - $seconds) . ';', file_get_contents($sessionFile), 1, $count);
                expect($count === 1 && file_put_contents($sessionFile, $session, LOCK_EX) !== false, 'Synthetic session clock updated.');
                redirectIs($owner->send('/settings/account'), '/login');
                redirectIs($owner->submit('/login', ['email' => $email, 'password' => $replacement]), '/dashboard/parent');
            }
            $currentCookie = $owner->cookie;
            $query('UPDATE users SET suspended_at = UTC_TIMESTAMP(6), auth_version = auth_version + 1 WHERE id = ?', [$id]);
            redirectIs($owner->send('/settings/account'), '/login');
            expect($owner->submit('/login', ['email' => $email, 'password' => $replacement])[0] === 403, 'Suspended account cannot sign in.');
            $query('UPDATE users SET suspended_at = NULL WHERE id = ?', [$id]);
            $owner->cookie = $currentCookie;
            redirectIs($owner->send('/settings/account'), '/login');
            $logs = (is_file($work . '/app.log') ? file_get_contents($work . '/app.log') : '') . file_get_contents($work . '/server.log');
            expect(!str_contains($logs, $password) && !str_contains($logs, $replacement), 'Credentials do not appear in request logs.');
        });
    serve($root, ['APP_ENV' => 'production', 'APP_URL' => 'https://example.test', 'AUTH_SESSION_DIR' => $work . '/sessions',
        'APP_LOG_FILE' => $work . '/app.log'], $work . '/production-server.log', static function (string $base): void {
            [$status, $headers] = request($base, '/login');
            $cookie = strtolower($headers['set-cookie'] ?? '');
            expect($status === 200 && str_contains($cookie, '; secure') && str_contains($cookie, '; httponly') && str_contains($cookie, 'samesite=lax'),
                'Production authentication cookies carry Secure, HttpOnly and SameSite.');
        });
    echo "PASS unique-fixture auth security: verification expiry/email binding/replay, session rotation/CSRF/idle/absolute expiry/production cookies, password reauth/reset expiry/replay/session revocation, suspended sessions, encrypted challenges and stale delivery suppression.\n";
} finally {
    foreach ($ids as $id) {
        $query('DELETE FROM email_delivery_attempts WHERE outbox_id IN (SELECT id FROM email_outbox WHERE user_id = ?)', [$id]);
        $query('DELETE FROM email_outbox WHERE user_id = ?', [$id]);
        $query('DELETE FROM audit_logs WHERE actor_user_id = ?', [$id]);
        $query('DELETE FROM users WHERE id = ?', [$id]);
        foreach (['settings-account', 'settings-action-password', 'settings-action-confirm_email'] as $scope) {
            $query('DELETE FROM auth_rate_limits WHERE bucket = ?', [hash('sha256', $scope . "\0" . $id, true)]);
        }
    }
    foreach (['login-account' => $email, 'login-identity' => Furoeducare\Modules\Auth\RateLimiter::network('127.0.0.1') . "\0" . $email] as $scope => $identity) {
        $query('DELETE FROM auth_rate_limits WHERE bucket = ?', [hash('sha256', $scope . "\0" . $identity, true)]);
    }
}

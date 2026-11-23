<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Core\HttpException;
use Furoeducare\Modules\Auth\AuthService;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Notification\NotificationEvents;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/auth-browser.php';

function notificationStatus(array $response, int $status, string $message): void
{
    expect($response[0] === $status, "$message Expected HTTP $status, got {$response[0]}. " . substr(strip_tags($response[2]), -600));
}

function notificationIds(string $html): array
{
    preg_match_all('~/notifications/open\?id=([0-9]+)~', html_entity_decode($html, ENT_QUOTES), $matches);
    return array_values(array_unique(array_map('intval', $matches[1])));
}

$root = dirname(__DIR__);
$work = $root . '/storage/local-preview/notifications-' . bin2hex(random_bytes(6));
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_notifications_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback development schema named furoeducare_test_notifications_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty notifications test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
    expect(mkdir($work, 0700, true), 'Create private notification test artifacts.');
    putenv('MAIL_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    putenv('MAIL_TRANSPORT=file');
    $password = 'Notification synthetic fixture 852!';
    $ids = [];
    foreach (['admin', 'teacher', 'school', 'parent', 'other', 'unverified'] as $name) {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, ?)')
            ->execute([$name . '@example.test', password_hash($password, PASSWORD_DEFAULT), $name === 'unverified' ? null : gmdate('Y-m-d H:i:s')]);
        $ids[$name] = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')
            ->execute([$ids[$name], in_array($name, ['other', 'unverified'], true) ? 'teacher' : $name]);
        if ($name === 'admin') {
            grantTestStaffRole($db, $ids[$name]);
        }
    }
    $emit = static function (int $user, string $key, string $title = 'Synthetic notification', string $category = 'recruitment', ?string $template = null, string $path = '/dashboard/teacher') use ($db, $ids): int {
        return Database::transaction($db, static fn (PDO $db): int => NotificationEvents::emit($db, $ids['admin'], $user,
            'test.notification', $key, $category, $title, 'Private fixture <script>alert(43)</script>', $path, $template));
    };
    $ledgerCount = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM verification_notifications')->fetchColumn();
    $mailCount = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();
    $first = $emit($ids['teacher'], 'notification:stable', 'Stable event', 'recruitment', 'new_application');
    expect($emit($ids['teacher'], 'notification:stable', 'Stable event', 'recruitment', 'new_application') === $first
        && $ledgerCount() === 1 && $mailCount() === 1, 'Identical retries create exactly one ledger and email row.');
    try {
        Database::transaction($db, static function (PDO $db) use ($ids): void {
            $db->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('test.notification_rollback', 'true')");
            NotificationEvents::emit($db, $ids['admin'], $ids['teacher'], 'test.notification', 'notification:stable',
                'recruitment', 'Conflicting event', 'Changed content', '/dashboard/teacher', 'new_application');
        });
        throw new RuntimeException('A conflicting key unexpectedly succeeded.');
    } catch (HttpException|DomainException $error) {
        expect(($error instanceof HttpException ? $error->status : $error->getCode()) === 409, 'Conflicting event payloads report HTTP 409.');
    }
    expect($ledgerCount() === 1 && $mailCount() === 1
        && (int) $db->query("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'test.notification_rollback'")->fetchColumn() === 0,
        'Conflict rolls back the caller business write and creates no deliveries.');
    $db->beginTransaction();
    NotificationEvents::emit($db, null, $ids['teacher'], 'test.notification', 'notification:rollback', 'recruitment', 'Rollback', 'Rollback event', '/dashboard/teacher', 'new_application');
    $db->rollBack();
    expect($ledgerCount() === 1 && $mailCount() === 1, 'Business rollback removes both notification channels.');
    foreach (['https://attacker.example.test', '//attacker.example.test', '/dashboard/../login', '/%2e%2e/login', '/dashboard\\teacher', "/dashboard/teacher\r\nLocation: https://attacker.example.test"] as $index => $path) {
        $rejected = false;
        try { $emit($ids['teacher'], 'invalid:path:' . $index, 'Unsafe path', 'recruitment', null, $path); }
        catch (InvalidArgumentException|HttpException|DomainException) { $rejected = true; }
        expect($rejected && $ledgerCount() === 1, 'External, encoded traversal and header-injecting destinations are rejected before persistence.');
    }
    echo "PASS event idempotency, conflict atomicity, rollback and internal link validation.\n";

    serve($root, ['APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000', 'AUTH_SESSION_DIR' => $work . '/sessions',
        'AUTH_MAIL_DIR' => $work . '/mail', 'MAIL_TRANSPORT' => 'file', 'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log',
        static function (string $base) use ($db, $ids, $password, $emit, $first, $ledgerCount, $mailCount): void {
        $guest = new AuthBrowser($base);
        foreach (['/notifications', '/notifications/count', '/notifications/open?id=' . $first, '/settings/notifications'] as $path) redirectIs($guest->send($path), '/login');
        $browsers = [];
        foreach ($ids as $name => $id) {
            $browser = $browsers[$name] = new AuthBrowser($base);
            notificationStatus($browser->submit('/login', ['email' => $name . '@example.test', 'password' => $password]), $name === 'unverified' ? 403 : 303, 'Fixture login.');
        }
        foreach (['admin', 'teacher', 'school', 'parent'] as $role) {
            $settings = $browsers[$role]->send('/settings/notifications');
            notificationStatus($settings, 200, 'Each registered role can configure notifications.');
            expect(str_contains($settings[2], 'href="/notifications"') && str_contains($settings[2], 'in_app[]') && str_contains($settings[2], 'email[]'),
                'Shared header has a bell and settings expose independent channels for every role.');
            notificationStatus($browsers[$role]->send('/notifications'), 200, 'Each role can access the inbox.');
        }
        $teacher = $browsers['teacher'];
        $other = $browsers['other'];
        $feed = $teacher->send('/notifications');
        expect(str_contains($feed[2], '&lt;script&gt;alert(43)&lt;/script&gt;') && !str_contains($feed[2], '<script>alert(43)</script>'), 'Notification text is escaped.');
        foreach ([$feed, $teacher->send('/notifications/count'), $teacher->send('/settings/notifications')] as $response) {
            expect(str_contains($response[1]['cache-control'] ?? '', 'no-store'), 'Private notification responses prevent caching.');
        }
        $count = static function (AuthBrowser $browser): int {
            $response = $browser->send('/notifications/count');
            notificationStatus($response, 200, 'Unread count endpoint.');
            expect(str_contains($response[1]['content-type'] ?? '', 'application/json'), 'Count is JSON.');
            $body = json_decode($response[2], true, 512, JSON_THROW_ON_ERROR);
            return (int) $body['unread'];
        };
        expect($count($teacher) === 1 && $count($other) === 0, 'Counts are recipient scoped.');
        notificationStatus($other->send('/notifications/open?id=' . $first), 404, 'Other users cannot open a notification.');
        notificationStatus($other->submit('/notifications/read', ['action' => 'read', 'id' => $first], '/settings/notifications'), 404, 'Other users cannot read a notification.');
        notificationStatus($teacher->send('/notifications/read', 'POST', ['action' => 'read', 'id' => $first]), 403, 'Read actions require CSRF.');
        expect($teacher->send('/notifications/read?id=' . $first)[0] >= 400, 'Read endpoint rejects GET.');
        redirectIs($teacher->send('/notifications/open?id=' . $first), '/dashboard/teacher');
        expect($count($teacher) === 1, 'Opening via GET cannot mark a notification read.');
        $db->prepare('UPDATE verification_notifications SET path = ? WHERE id = ?')->execute(['//attacker.example.test', $first]);
        redirectIs($teacher->send('/notifications/open?id=' . $first), '/notifications');
        $db->prepare('UPDATE verification_notifications SET path = ? WHERE id = ?')->execute(['/dashboard/teacher', $first]);
        notificationStatus($teacher->submit('/notifications/read', ['action' => 'read', 'id' => $first], '/settings/notifications'), 303, 'Owner can mark one read.');
        expect($count($teacher) === 0, 'Reading one updates unread count.');
        $readAt = $db->query('SELECT read_at FROM verification_notifications WHERE id = ' . $first)->fetchColumn();
        notificationStatus($teacher->submit('/notifications/read', ['action' => 'read', 'id' => $first], '/settings/notifications'), 303, 'Read retries succeed.');
        expect($db->query('SELECT read_at FROM verification_notifications WHERE id = ' . $first)->fetchColumn() === $readAt, 'Read retry preserves the original timestamp.');
        $settings = '/settings/notifications';
        notificationStatus($teacher->send($settings, 'POST', ['channels' => 'independent']), 403, 'Preferences require CSRF.');
        foreach ([['in_app' => ['unknown']], ['email' => 'recruitment']] as $invalid) {
            notificationStatus($teacher->submit($settings, $invalid + ['channels' => 'independent']), 422, 'Malformed category submissions are rejected.');
        }
        notificationStatus($teacher->submit($settings, ['channels' => 'independent', 'in_app' => [], 'email' => ['recruitment'], 'user_id' => $ids['other']]), 422, 'Preferences reject an impersonated owner.');
        notificationStatus($teacher->submit($settings, ['channels' => 'independent', 'in_app' => [], 'email' => ['recruitment']]), 303, 'In-app opt out preserves email selection.');
        $beforeMail = $mailCount();
        $hidden = $emit($ids['teacher'], 'channels:hidden', 'Hidden fixture', 'recruitment', 'new_application');
        expect($mailCount() === $beforeMail + 1 && $count($teacher) === 0 && !in_array($hidden, notificationIds($teacher->send('/notifications')[2]), true), 'Email-enabled in-app-disabled records remain hidden.');
        expect($db->query('SELECT in_app_visible FROM verification_notifications WHERE id = ' . $hidden)->fetchColumn() == 0, 'Disabled delivery retains a ledger row.');
        notificationStatus($teacher->send('/notifications/open?id=' . $hidden), 404, 'Suppressed events cannot be opened through guessed IDs.');
        notificationStatus($teacher->submit('/notifications/read', ['action' => 'read', 'id' => $hidden], $settings), 404, 'Suppressed events cannot be marked read through guessed IDs.');
        expect($count($other) === 0 && (int) $db->query('SELECT COUNT(*) FROM in_app_notification_preferences WHERE user_id = ' . $ids['other'])->fetchColumn() === 0, 'Submitted user ID cannot impersonate another preference owner.');
        notificationStatus($teacher->submit($settings, ['channels' => 'independent', 'in_app' => ['recruitment'], 'email' => []]), 303, 'In-app and email can use opposite settings.');
        expect($emit($ids['teacher'], 'channels:hidden', 'Hidden fixture', 'recruitment', 'new_application') === $hidden && $mailCount() === $beforeMail + 1 && $count($teacher) === 0, 'Re-enabling a channel cannot redeliver a previously suppressed event.');
        $visible = $emit($ids['teacher'], 'channels:visible', 'Visible fixture', 'recruitment', 'new_application');
        expect($mailCount() === $beforeMail + 1 && $count($teacher) === 1, 'In-app-enabled email-disabled events appear without email.');
        notificationStatus($teacher->submit($settings, ['enabled' => ['account']]), 303, 'Legacy email-only preferences remain accepted.');
        expect((int) $db->query('SELECT recruitment_enabled FROM in_app_notification_preferences WHERE user_id = ' . $ids['teacher'])->fetchColumn() === 1,
            'Legacy email form cannot change in-app choices.');
        $through = $emit($ids['teacher'], 'read:through');
        $newer = $emit($ids['teacher'], 'read:newer');
        $foreign = $emit($ids['other'], 'read:foreign');
        notificationStatus($teacher->submit('/notifications/read', ['action' => 'all', 'through' => $through, 'user_id' => $ids['other']], '/settings/notifications'), 422, 'Mark-all rejects an impersonated owner.');
        notificationStatus($teacher->submit('/notifications/read', ['action' => 'all', 'through' => $through], '/settings/notifications'), 303, 'Mark all honors a snapshot boundary.');
        expect($count($teacher) === 1 && $count($other) === 1
            && $db->query('SELECT read_at FROM verification_notifications WHERE id = ' . $hidden)->fetchColumn() === null,
            'Mark-all leaves newer, other-owner and hidden rows unchanged.');
        expect(notificationIds($teacher->send('/notifications?unread=1')[2]) === [$newer], 'Unread filter excludes read rows.');
        for ($i = 0; $i < 24; $i++) $emit($ids['teacher'], 'page:' . $i);
        $page = $teacher->send('/notifications?category=recruitment');
        $pageIds = notificationIds($page[2]);
        expect(count($pageIds) === 20, 'Inbox limits one page to twenty items.');
        $second = notificationIds($teacher->send('/notifications?category=recruitment&before=' . min($pageIds))[2]);
        expect(count($second) >= 6 && array_intersect($pageIds, $second) === [], 'Cursor pages contain disjoint older records.');
        expect(notificationIds($teacher->send('/notifications?category=messages')[2]) === [], 'Category filter excludes unrelated events.');
        $db->exec('UPDATE users SET suspended_at = CURRENT_TIMESTAMP(6), auth_version = auth_version + 1 WHERE id = ' . $ids['teacher']);
        redirectIs($teacher->send('/notifications'), '/login');
        redirectIs($teacher->send('/notifications/count'), '/login');
        redirectIs($teacher->send($settings, 'POST', ['channels' => 'independent']), '/login');
        redirectIs($browsers['unverified']->send('/notifications'), '/login');
        echo "PASS four roles, private responses, escaping, ownership, CSRF, independent channels, bounded mark-all, pagination and account revocation.\n";
    });
    $auth = new AuthService(static fn (): PDO => $db);
    $resetToken = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO password_reset_tokens (token_hash, user_id, expires_at) VALUES (?, ?, UTC_TIMESTAMP(6) + INTERVAL 30 MINUTE)')
        ->execute([hash('sha256', $resetToken, true), $ids['other']]);
    $beforeLedger = $ledgerCount();
    $beforeMail = $mailCount();
    expect($auth->resetPassword($resetToken, 'Changed notification fixture 591!'), 'Actual password reset workflow completes.');
    expect(!$auth->resetPassword($resetToken, 'Repeated notification fixture 591!') && $ledgerCount() === $beforeLedger + 1 && $mailCount() === $beforeMail + 1,
        'Password reset records one security notification and email, including token retries.');
    $verifyToken = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO email_verification_tokens (token_hash, user_id, email_at_issue, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6) + INTERVAL 30 MINUTE)')
        ->execute([hash('sha256', $verifyToken, true), $ids['unverified'], 'unverified@example.test']);
    $beforeLedger = $ledgerCount();
    $beforeMail = $mailCount();
    try {
        $auth->confirmProfileEmail(Role::Teacher, $verifyToken, 'unverified@example.test', static fn (): string => password_hash($password, PASSWORD_DEFAULT),
            static function (int $id): void { throw new RuntimeException('fixture profile rollback'); });
        throw new RuntimeException('Expected profile activation rollback.');
    } catch (RuntimeException $error) {
        expect($error->getMessage() === 'fixture profile rollback', 'Profile callback failure propagates.');
    }
    expect($ledgerCount() === $beforeLedger && $mailCount() === $beforeMail, 'Failed activation rolls back welcome notification and mail.');
    $account = $auth->confirmProfileEmail(Role::Teacher, $verifyToken, 'unverified@example.test', static fn (): string => password_hash($password, PASSWORD_DEFAULT), static fn (int $id): null => null);
    expect($account !== null && $ledgerCount() === $beforeLedger + 1 && $mailCount() === $beforeMail + 1,
        'Actual activation atomically records welcome notification and mail.');
    expect($auth->confirmProfileEmail(Role::Teacher, $verifyToken, 'unverified@example.test', static fn (): string => 'unused', static fn (int $id): null => null) === null
        && $ledgerCount() === $beforeLedger + 1 && $mailCount() === $beforeMail + 1, 'Activation token retry cannot duplicate welcome delivery.');
    echo "PASS account activation and password reset notifications, retries and workflow rollback.\n";
    echo "PASS central notification integration.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

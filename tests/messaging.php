<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Messaging\MessageRepository;
use Furoeducare\Modules\Messaging\MessageContext;
use Furoeducare\Modules\Auth\RateLimiter;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/school-fixtures.php';

function messagingRow(PDO $db, string $sql, array $params = []): array
{
    $query = $db->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll();
    expect(count($rows) === 1, 'Expected exactly one messaging fixture row.');
    return $rows[0];
}

function messagingStatus(array $response, int $expected, string $reason): void
{
    expect($response[0] === $expected, "$reason Expected HTTP $expected, got {$response[0]}.");
}

function messagingDenied(array $response, string $reason): void
{
    expect(in_array($response[0], [403, 404, 409, 422, 429], true), "$reason Got HTTP {$response[0]}.");
}

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-messaging-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    expect(mkdir($work, 0700), 'Create messaging test directory.');
    if (in_array('--routes', $argv, true)) {
        serve($root, ['APP_ENV' => 'development', 'AUTH_SESSION_DIR' => $work . '/sessions', 'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log', static function (string $base): void {
            $guest = new AuthBrowser($base);
            redirectIs($guest->send('/messages'), '/login');
            redirectIs($guest->send('/dashboard/admin/messages'), '/login');
        });
        echo "PASS guest messaging routes require authentication.\n";
    } else {
        $config = Config::load($root);
        expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
            && preg_match('/\Afuroeducare_test_messaging_[a-z0-9_]+\z/', $config->databaseName) === 1,
            'Use an empty loopback development schema named furoeducare_test_messaging_<suffix>.');
        $db = Database::connect($config);
        expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty messaging test schema.');
        $migrations = new MigrationRunner($db, $root . '/database/migrations');
        $migrations->migrate();
        $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
        $password = 'Messaging synthetic fixture 529!';
        $ids = [];
        foreach (['staff' => 'admin', 'other-staff' => 'admin', 'teacher' => 'teacher', 'school' => 'school', 'parent' => 'parent', 'stranger' => 'parent', 'limited' => 'teacher'] as $name => $role) {
            $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
                ->execute([$name . '@example.test', password_hash($password, PASSWORD_DEFAULT)]);
            $ids[$name] = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$ids[$name], $role]);
            if ($role === 'admin') {
                grantTestStaffRole($db, $ids[$name]);
            }
        }
        $database = static fn (): PDO => $db;
        (new Furoeducare\Modules\School\SchoolRepository($database))->create($ids['school'], schoolFields('school@example.test', $password));
        (new Furoeducare\Modules\Parent\ParentRepository($database))->create($ids['parent'], ['first_name' => 'Synthetic', 'last_name' => 'Guardian', 'phone' => '+2348012345678', 'state_code' => 'LA', 'city' => 'Ikeja']);
        $db->prepare("INSERT INTO vacancies (school_id, content) VALUES (?, '{}')")->execute([$ids['school']]);
        $vacancy = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO parent_tutoring_requests (parent_id, subject, requirements) VALUES (?, 'Mathematics', 'Synthetic lesson request')")->execute([$ids['parent']]);
        $tutoring = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO candidate_requests (requester_id, requester_role, category, purpose, requirements) VALUES (?, 'school', 'recruitment', 'Synthetic recruitment', 'Synthetic candidate requirements')")->execute([$ids['school']]);
        $recruitment = (int) $db->lastInsertId();
        serve($root, ['APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000', 'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log', static function (string $base) use ($db, $ids, $password, $vacancy, $tutoring, $recruitment): void {
            $path = '/messages';
            $admin = '/dashboard/admin/messages';
            $guest = new AuthBrowser($base);
            redirectIs($guest->send($path), '/login');
            redirectIs($guest->send($admin), '/login');
            $browsers = [];
            foreach ($ids as $name => $id) {
                $browser = $browsers[$name] = new AuthBrowser($base);
                $role = str_contains($name, 'staff') ? 'admin' : (in_array($name, ['stranger'], true) ? 'parent' : ($name === 'limited' ? 'teacher' : $name));
                redirectIs($browser->submit('/login', ['email' => $name . '@example.test', 'password' => $password]), '/dashboard/' . $role);
            }
            $staff = $browsers['staff'];
            $row = static fn (int $id): array => messagingRow($db, 'SELECT * FROM message_conversations WHERE id = ?', [$id]);
            $count = static fn (int $id): int => (int) messagingRow($db, 'SELECT COUNT(*) AS n FROM conversation_messages WHERE conversation_id = ?', [$id])['n'];
            $create = static function (AuthBrowser $browser, string $route, array $fields = []) use ($row): int {
                $response = $browser->submit($route, $fields + ['action' => 'create', 'subject' => 'Synthetic support request', 'body' => 'Please help with my account.', 'context_type' => 'support', 'token' => bin2hex(random_bytes(16))]);
                messagingStatus($response, 303, 'Create a conversation.');
                expect(preg_match('~\?id=([1-9][0-9]*)$~', $response[1]['location'] ?? '', $match) === 1, 'Creation redirects to the conversation.');
                $id = (int) $match[1];
                expect($row($id)['status'] === ($route === '/messages' ? 'open' : 'pending'), 'New conversation status reflects the initiating sender.');
                return $id;
            };
            $manage = static fn (int $id, string $action, array $fields = []): array => $staff->submit($admin, $fields + ['id' => $id, 'revision' => $row($id)['revision'], 'action' => $action], $admin . '?id=' . $id);
            $send = static fn (AuthBrowser $browser, string $route, int $id, array $fields = []): array => $browser->submit($route, $fields + ['action' => 'send', 'id' => $id, 'body' => 'Synthetic reply', 'token' => bin2hex(random_bytes(16))], $route . '?id=' . $id);
            $threads = [];
            foreach (['teacher', 'school', 'parent'] as $role) {
                $browser = $browsers[$role];
                messagingDenied($browser->send($admin), 'Participants cannot access management.');
                $id = $threads[$role] = $create($browser, $path, ['owner_id' => $ids['stranger'], 'sender_id' => $ids['staff']]);
                expect((int) $row($id)['owner_id'] === $ids[$role] && $row($id)['assigned_admin_id'] === null, 'Session determines the owner and new threads are unassigned.');
                $message = messagingRow($db, 'SELECT * FROM conversation_messages WHERE conversation_id = ?', [$id]);
                expect((int) $message['sender_id'] === $ids[$role] && !(bool) $message['is_staff'], 'Session determines the sender.');
                messagingStatus($browsers['stranger']->send($path . '?id=' . $id), 404, 'Other owners cannot read a thread.');
                messagingDenied($browsers['stranger']->submit($path, ['action' => 'send', 'id' => $id, 'body' => 'Intrusion', 'token' => bin2hex(random_bytes(16))], $path), 'Other owners cannot send.');
                messagingDenied($send($staff, $admin, $id), 'Unassigned staff cannot reply.');
                redirectIs($manage($id, 'assign'), $admin . '?id=' . $id);
                expect((int) $row($id)['assigned_admin_id'] === $ids['staff'], 'Assignment records the acting staff.');
                redirectIs($send($staff, $admin, $id), $admin . '?id=' . $id);
                expect($row($id)['status'] === 'pending', 'Staff reply sets pending.');
                $token = bin2hex(random_bytes(16));
                $body = '<script>alert("synthetic")</script> & plain text';
                redirectIs($send($browser, $path, $id, ['body' => $body, 'token' => $token]), $path . '?id=' . $id);
                $before = $count($id);
                redirectIs($send($browser, $path, $id, ['body' => $body, 'token' => $token]), $path . '?id=' . $id);
                expect($count($id) === $before && $row($id)['status'] === 'open', 'Retry is idempotent; owner reply reopens pending.');
                $stored = messagingRow($db, 'SELECT * FROM conversation_messages WHERE conversation_id = ? AND client_token = ?', [$id, $token]);
                expect($stored['body'] === $body, 'Store the original plain text.');
                $page = $browser->send($path . '?id=' . $id);
                messagingStatus($page, 200, 'Owner detail.');
                expect(!str_contains($page[2], '<script>alert(') && str_contains($page[2], '&lt;script&gt;'), 'Escape message markup when rendering.');
                expect(str_contains($page[1]['cache-control'] ?? '', 'no-store'), 'Private messages prevent caching.');
                messagingStatus($browser->send($path, 'POST', ['action' => 'send', 'id' => $id, 'body' => 'No CSRF']), 403, 'Sending requires CSRF.');
                $last = (int) $row($id)['last_message_id'];
                foreach ([[$browser, $path, $ids[$role]], [$staff, $admin, $ids['staff']], [$browsers['other-staff'], $admin, $ids['other-staff']]] as [$reader, $route, $user]) {
                    $response = $reader->submit($route . '/read', ['id' => $id, 'last_id' => $last], $route . '?id=' . $id);
                    messagingStatus($response, 200, 'Read acknowledgement.');
                    expect(is_array(json_decode($response[2], true, flags: JSON_THROW_ON_ERROR)), 'Read acknowledgement returns JSON.');
                    $read = messagingRow($db, 'SELECT * FROM conversation_reads WHERE conversation_id = ? AND user_id = ?', [$id, $user]);
                    expect((int) $read['last_read_message_id'] === $last, 'Each account has its own read cursor.');
                }
                $revision = $row($id)['revision'];
                redirectIs($manage($id, 'status', ['status' => 'closed']), $admin . '?id=' . $id);
                $closedCount = $count($id);
                redirectIs($send($browser, $path, $id, ['body' => $body, 'token' => $token]), $path . '?id=' . $id);
                expect($count($id) === $closedCount && $row($id)['status'] === 'closed', 'A committed send retry after closure is a no-op.');
                messagingDenied($staff->submit($admin, ['action' => 'status', 'id' => $id, 'revision' => $revision, 'status' => 'open'], $admin . '?id=' . $id), 'Stale transitions are rejected.');
                messagingDenied($send($browser, $path, $id), 'Closed conversations reject owner replies.');
                messagingDenied($send($staff, $admin, $id), 'Closed conversations reject staff replies.');
                redirectIs($manage($id, 'status', ['status' => 'open']), $admin . '?id=' . $id);
                redirectIs($send($browser, $path, $id), $path . '?id=' . $id);
            }
            echo "PASS roles, ownership, assignment, escaping, CSRF, retry, read cursors and lifecycle.\n";
            foreach ([['school', 'vacancy', $vacancy], ['school', 'recruitment', $recruitment], ['parent', 'tutoring', $tutoring]] as [$role, $context, $source]) {
                $linked = $create($browsers[$role], $path, ['subject' => 'Linked synthetic case', 'context_type' => $context, 'context_id' => $source]);
                expect($row($linked)['context_type'] === $context && (int) $row($linked)['context_id'] === $source, 'Authorized context linkage is preserved.');
                messagingStatus($browsers['stranger']->submit($path, ['action' => 'create', 'subject' => 'Foreign linked request', 'body' => 'Synthetic probe', 'context_type' => $context, 'context_id' => $source, 'token' => bin2hex(random_bytes(16))]), 404, 'Existing contexts belonging to another account are denied.');
            }
            foreach (['subject' => str_repeat('s', 161), 'body' => str_repeat('b', 4001), 'context_type' => 'unknown'] as $field => $value) {
                messagingDenied($browsers['parent']->submit($path, ['action' => 'create', 'subject' => 'Validation fixture', 'body' => 'Validation fixture', 'context_type' => 'support', 'token' => bin2hex(random_bytes(16)), $field => $value]), 'Reject invalid ' . $field . '.');
            }
            foreach (['recruitment', 'candidate_information', 'vacancy', 'tutoring', 'placement'] as $context) {
                messagingDenied($browsers['parent']->submit($path, ['action' => 'create', 'subject' => 'Foreign context', 'body' => 'Synthetic context probe', 'context_type' => $context, 'context_id' => 99999999, 'token' => bin2hex(random_bytes(16))]), 'Reject inaccessible ' . $context . ' context.');
            }
            foreach (['q=Synthetic', 'status=open', 'status=pending', 'status=closed', 'assigned=me', 'assigned=unassigned', 'context_type=support', 'page=2'] as $filter) {
                messagingStatus($staff->send($admin . '?' . $filter), 200, 'Management list accepts ' . $filter . '.');
            }
            $staffCreated = $create($staff, $admin, ['owner_id' => $ids['parent'], 'owner_role' => 'parent', 'subject' => 'Management initiated unique fixture']);
            expect((int) $row($staffCreated)['assigned_admin_id'] === $ids['staff'], 'Staff-created conversations are assigned to the actor.');
            $filtered = $staff->send($admin . '?q=Management+initiated+unique+fixture&status=pending&assigned=me&context_type=support');
            messagingStatus($filtered, 200, 'Combined management filters.');
            expect(str_contains($filtered[2], 'Management initiated unique fixture') && !str_contains($filtered[2], 'Synthetic support request'), 'Combined filters return only matching conversations.');
            $filtered = $staff->send($admin . '?q=Management+initiated+unique+fixture&status=closed');
            expect(!str_contains($filtered[2], '>Management initiated unique fixture<'), 'Status filters exclude nonmatching conversations.');
            foreach (['teacher', 'school', 'parent', 'staff'] as $recipient) {
                expect((int) messagingRow($db, "SELECT COUNT(*) AS n FROM verification_notifications WHERE user_id = ? AND event = 'messaging.new_message'", [$ids[$recipient]])['n'] > 0, 'Participants and assigned staff receive message notifications.');
            }
            expect((int) messagingRow($db, "SELECT COUNT(*) AS n FROM verification_notifications WHERE user_id = ? AND event = 'messaging.new_message'", [$ids['stranger']])['n'] === 0, 'Unrelated owners receive no message notifications.');
            expect((int) messagingRow($db, "SELECT COUNT(*) AS n FROM verification_notifications WHERE event = 'messaging.new_message' AND (message LIKE '%synthetic%' OR message LIKE '%script%')")['n'] === 0, 'Notifications omit private message contents.');
            $limited = $browsers['limited'];
            $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin'")->execute([$ids['limited']]);
            grantTestStaffRole($db, $ids['limited']);
            for ($i = 0; $i < 10; $i++) {
                $limitId = $create($limited, $path, ['subject' => 'Rate limit fixture ' . $i]);
                if ($i === 0) {
                    messagingStatus($limited->submit($path . '/read', ['id' => $limitId, 'last_id' => $row($limitId)['last_message_id']], $path . '?id=' . $limitId), 200, 'Multi-role owner read.');
                    expect(str_contains($limited->send($path . '?id=' . $limitId)[2], '>Unread</p>'), 'An owner with an admin role cannot supply their own management read receipt.');
                    messagingStatus($staff->submit($admin . '/read', ['id' => $limitId, 'last_id' => $row($limitId)['last_message_id']], $admin . '?id=' . $limitId), 200, 'Separate staff read.');
                    expect(str_contains($limited->send($path . '?id=' . $limitId)[2], '>Read</p>'), 'A different administrator supplies the management read receipt.');
                }
            }
            messagingStatus($limited->submit($path, ['action' => 'create', 'subject' => 'Excess request', 'body' => 'Rate limit fixture', 'context_type' => 'support', 'token' => bin2hex(random_bytes(16))]), 429, 'Ten creations per hour.');
            $db->exec('DELETE FROM auth_rate_limits');
            $readId = $threads['teacher'];
            $ownerRead = messagingRow($db, 'SELECT last_read_message_id FROM conversation_reads WHERE conversation_id = ? AND user_id = ?', [$readId, $ids['teacher']])['last_read_message_id'];
            redirectIs($send($staff, $admin, $readId), $admin . '?id=' . $readId);
            expect(messagingRow($db, 'SELECT last_read_message_id FROM conversation_reads WHERE conversation_id = ? AND user_id = ?', [$readId, $ids['teacher']])['last_read_message_id'] === $ownerRead, 'Staff replies do not mark messages read for the owner.');
            messagingStatus($browsers['stranger']->submit($path . '/read', ['id' => $readId, 'last_id' => $row($readId)['last_message_id']], $path), 404, 'Other owners cannot acknowledge reads.');
            messagingStatus($browsers['teacher']->send($path . '/read', 'POST', ['id' => $readId, 'last_id' => $row($readId)['last_message_id']]), 403, 'Read acknowledgement requires CSRF.');
            for ($i = 0; $i < 30; $i++) {
                redirectIs($send($browsers['teacher'], $path, $threads['teacher']), $path . '?id=' . $threads['teacher']);
            }
            messagingStatus($send($browsers['teacher'], $path, $threads['teacher']), 429, 'Thirty sends per minute.');
            $latest = $browsers['teacher']->send($path . '?id=' . $readId);
            messagingStatus($latest, 200, 'Latest message page.');
            preg_match_all('/data-message-id="([0-9]+)"/', $latest[2], $messageIds);
            expect(count($messageIds[1]) === 30, 'Message pages contain thirty messages.');
            $cursor = min(array_map('intval', $messageIds[1]));
            $older = $browsers['teacher']->send($path . '?id=' . $readId . '&before=' . $cursor);
            messagingStatus($older, 200, 'Older message page.');
            preg_match_all('/data-message-id="([0-9]+)"/', $older[2], $olderIds);
            expect($olderIds[1] !== [] && max(array_map('intval', $olderIds[1])) < $cursor, 'Message keyset pages exclude their boundary.');
            echo "PASS input limits, inaccessible contexts, filters, keyset pagination and account rate limits.\n";
            $id = $threads['parent'];
            redirectIs($manage($id, 'status', ['status' => 'closed']), $admin . '?id=' . $id);
            redirectIs($manage($id, 'hold', ['hold' => '1']), $admin . '?id=' . $id);
            $db->prepare('UPDATE message_conversations SET closed_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 366 DAY) WHERE id = ?')->execute([$id]);
            $database = static fn (): PDO => $db;
            $repository = new MessageRepository($database, new MessageContext($database), new RateLimiter($database));
            $repository->purge();
            expect($count($id) > 0, 'Retention hold preserves message content.');
            redirectIs($manage($id, 'hold', ['hold' => '0']), $admin . '?id=' . $id);
            expect($repository->purge() >= 1 && $count($id) === 0 && $row($id)['purged_at'] !== null, 'Expired closed messages are purged and metadata retained.');
            expect(!str_contains($row($id)['subject'], 'Synthetic'), 'Purge scrubs the subject.');
            messagingStatus($browsers['parent']->send($path . '?id=' . $id), 404, 'Purged content is unavailable.');
            messagingStatus($staff->send($admin . '?id=' . $id), 404, 'Purged staff detail is unavailable.');
            echo "PASS closed retention, hold and inaccessible purged content.\n";
        });
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    foreach ([$work . '/app.log', $work . '/server.log'] as $log) {
        if (is_file($log)) {
            fwrite(STDERR, implode('', array_slice(file($log), -8)));
        }
    }
    $exit = 1;
} finally {
    if ($exit === 0) {
        foreach ([$work . '/sessions', $work . '/mail', $work] as $directory) {
            if (is_dir($directory)) {
                foreach (glob($directory . '/*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($directory);
            }
        }
    }
}
exit($exit);

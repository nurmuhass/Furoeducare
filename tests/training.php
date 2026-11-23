<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\School\SchoolRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/school-fixtures.php';

function trainingStatus(array $response, int $status, string $message): void
{
    expect($response[0] === $status, "$message Expected HTTP $status, got {$response[0]}. " . substr(strip_tags($response[2]), -1000));
}

function trainingDenied(array $response, string $message): void
{
    expect(in_array($response[0], [403, 404, 409, 422], true), "$message Got HTTP {$response[0]}.");
}

$root = dirname(__DIR__);
$work = $root . '/storage/local-preview/training-' . bin2hex(random_bytes(6));
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_training_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback development schema named furoeducare_test_training_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty training test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
    mkdir($work, 0700, true);
    $password = 'Training synthetic fixture 951!';
    $ids = [];
    foreach (['staff' => 'admin', 'second-staff' => 'admin', 'school' => 'school', 'other-school' => 'school',
        'pending' => 'school', 'incomplete' => 'school', 'unverified' => 'school', 'teacher' => 'teacher', 'parent' => 'parent'] as $name => $role) {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, ?)')
            ->execute([$name . '@example.test', password_hash($password, PASSWORD_DEFAULT), $name === 'unverified' ? null : gmdate('Y-m-d H:i:s')]);
        $ids[$name] = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$ids[$name], $role]);
        if ($role === 'admin') {
            grantTestStaffRole($db, $ids[$name]);
        }
        if ($role === 'school') {
            (new SchoolRepository(static fn (): PDO => $db))->create($ids[$name], schoolFields($name . '@example.test', $password,
                ['school_name' => ucfirst($name) . ' Training School', 'city' => ucfirst($name) . ' City']));
            if ($name !== 'pending') {
                $db->prepare("UPDATE school_onboarding SET review_status = 'approved', approved_at = UTC_TIMESTAMP(6) WHERE user_id = ?")->execute([$ids[$name]]);
            }
            if ($name === 'incomplete') {
                $db->prepare('UPDATE school_onboarding SET accepted_at = NULL WHERE user_id = ?')->execute([$ids[$name]]);
            }
        }
    }
    serve($root, ['APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000', 'AUTH_SESSION_DIR' => $work . '/sessions',
        'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log',
        static function (string $base) use ($db, $ids, $password): void {
        $public = '/training-workshops';
        $catalogue = '/dashboard/admin/training';
        $schoolRoute = '/dashboard/school/training';
        $requests = '/dashboard/admin/training/requests';
        $guest = new AuthBrowser($base);
        trainingStatus($guest->send($public), 200, 'Public catalogue exists.');
        redirectIs($guest->send($catalogue), '/login');
        redirectIs($guest->send($schoolRoute), '/login');
        $browsers = [];
        foreach ($ids as $name => $id) {
            $browsers[$name] = new AuthBrowser($base);
            trainingStatus($browsers[$name]->submit('/login', ['email' => $name . '@example.test', 'password' => $password]), $name === 'unverified' ? 403 : 303, 'Fixture signs in or unverified account is denied.');
        }
        $admin = $browsers['staff'];
        $school = $browsers['school'];
        foreach (['school', 'teacher', 'parent'] as $name) {
            trainingStatus($browsers[$name]->send($catalogue), 403, 'Nonstaff cannot manage training.');
            trainingStatus($browsers[$name]->send($requests), 403, 'Nonstaff cannot access request queue.');
        }
        $moduleFields = static fn (array $overrides = []): array => $overrides + ['action' => 'save', 'kind' => 'module',
            'title' => 'Classroom <script>alert(91)</script> practice', 'description' => 'Practical <b>teaching</b> development',
            'category' => 'Pedagogy', 'objectives' => 'Improve <em>practice</em>', 'target_participants' => 'Teachers',
            'duration' => 'Two hours', 'delivery_format' => 'hybrid', 'prerequisites' => 'Teaching experience',
            'materials_metadata' => 'Participant workbook description'];
        $row = static function (string $table, int $id) use ($db): array {
            $query = $db->prepare("SELECT * FROM $table WHERE id = ?");
            $query->execute([$id]);
            return $query->fetch(PDO::FETCH_ASSOC) ?: [];
        };
        $newModule = $catalogue . '?kind=module&new=1';
        trainingStatus($admin->send($catalogue, 'POST', $moduleFields()), 403, 'Catalogue writes require CSRF.');
        trainingStatus($admin->submit($catalogue, $moduleFields(), $newModule), 303, 'Create module draft.');
        $module = (int) $db->query('SELECT MAX(id) FROM training_modules')->fetchColumn();
        $modulePage = $catalogue . '?kind=module&id=' . $module;
        $moduleAction = static fn (string $action, array $fields = []): array => $admin->submit($catalogue,
            $fields + ['action' => 'publish', 'status' => ['publish' => 'published', 'draft' => 'draft', 'archive' => 'archived'][$action], 'kind' => 'module', 'id' => $module, 'revision' => $row('training_modules', $module)['revision']], $modulePage);
        trainingStatus($guest->send($public . '/module?id=' . $module), 404, 'Draft module is private.');
        expect(!str_contains($guest->send($public)[2], 'Classroom &lt;script&gt;'), 'Draft is absent from catalogue.');
        trainingStatus($moduleAction('publish'), 303, 'Publish module.');
        $html = $guest->send($public . '/module?id=' . $module);
        trainingStatus($html, 200, 'Published module is public.');
        expect(str_contains($html[2], '&lt;script&gt;') && !str_contains($html[2], '<script>alert(91)</script>')
            && str_contains($html[2], '&lt;b&gt;teaching&lt;/b&gt;'), 'Public module metadata is escaped.');
        trainingStatus($admin->submit($catalogue, $moduleFields(['title' => 'Second available module']), $newModule), 303, 'Create second module.');
        $second = (int) $db->query('SELECT MAX(id) FROM training_modules')->fetchColumn();
        trainingStatus($admin->submit($catalogue, ['action' => 'publish', 'status' => 'published', 'kind' => 'module', 'id' => $second,
            'revision' => $row('training_modules', $second)['revision']], $catalogue . '?kind=module&id=' . $second), 303, 'Publish second module.');
        $packageFields = ['action' => 'save', 'kind' => 'package', 'title' => 'School development package',
            'description' => 'Two ordered sessions', 'module_ids' => [$second, $module]];
        trainingDenied($admin->submit($catalogue, array_replace($packageFields, ['module_ids' => [$module, $module]]),
            $catalogue . '?kind=package&new=1'), 'Duplicate package members are rejected.');
        trainingStatus($admin->submit($catalogue, $packageFields, $catalogue . '?kind=package&new=1'), 303, 'Create package.');
        $package = (int) $db->query('SELECT MAX(id) FROM training_packages')->fetchColumn();
        $packagePage = $catalogue . '?kind=package&id=' . $package;
        trainingStatus($guest->send($public . '/package?id=' . $package), 404, 'Draft package is private.');
        trainingStatus($admin->submit($catalogue, ['action' => 'publish', 'status' => 'published', 'kind' => 'package', 'id' => $package,
            'revision' => $row('training_packages', $package)['revision']], $packagePage), 303, 'Publish package.');
        trainingStatus($guest->send($public . '/package?id=' . $package), 200, 'Published package is public.');
        $intake = static fn (array $fields = []): array => $fields + ['action' => 'submit', 'participants' => '24',
            'location' => 'Abeokuta campus', 'preferred_dates' => date('Y-m-d', time() + 86400 * 7),
            'requirements' => 'Private school requirements 427', 'module_ids' => [$module], 'client_token' => bin2hex(random_bytes(16))];
        $csrf = $school->form($schoolRoute . '?new=1');
        foreach (['pending', 'incomplete', 'teacher', 'parent'] as $name) {
            $browser = $browsers[$name];
            $token = $browser->form('/dashboard/' . (in_array($name, ['teacher', 'parent'], true) ? $name : 'school'));
            trainingDenied($browser->send($schoolRoute, 'POST', $intake() + ['_csrf' => $token]), 'Only an approved verified school can submit.');
        }
        redirectIs($browsers['unverified']->send($schoolRoute, 'POST', $intake() + ['_csrf' => $browsers['unverified']->form('/login')]), '/login');
        trainingStatus($school->send($schoolRoute, 'POST', $intake()), 403, 'School submission requires CSRF.');
        foreach ([['module_ids' => [$module, $module]], ['participants' => '0'], ['participants' => '10001'],
            ['preferred_dates' => date('Y-m-d', time() - 86400)], ['preferred_dates' => "2030-02-30"],
            ['module_ids' => [$module], 'package_id' => $package]] as $invalid) {
            trainingDenied($school->submit($schoolRoute, $intake($invalid), $schoolRoute . '?new=1'), 'Invalid intake is rejected.');
        }
        expect((int) $db->query('SELECT COUNT(*) FROM training_requests')->fetchColumn() === 0, 'Rejected submissions leave no partial requests.');
        $fields = $intake(['module_ids' => [], 'package_id' => $package]);
        trainingStatus($school->submit($schoolRoute, $fields, $schoolRoute . '?new=1'), 303, 'Approved school submits a package.');
        $id = (int) $db->query('SELECT MAX(id) FROM training_requests')->fetchColumn();
        $detail = $schoolRoute . '?id=' . $id;
        $staffPage = $requests . '?id=' . $id;
        $initial = $row('training_requests', $id);
        expect($initial['status'] === 'submitted' && $initial['package_title'] === 'School development package'
            && preg_match('/\ATR-[A-F0-9]{32}\z/', $initial['reference']) === 1, 'Submission captures package title and an opaque reference.');
        trainingStatus($school->submit($schoolRoute, $fields, $schoolRoute . '?new=1'), 303, 'Exact submission retry succeeds.');
        expect((int) $db->query('SELECT COUNT(*) FROM training_requests')->fetchColumn() === 1, 'Submission token prevents duplicates.');
        trainingDenied($school->submit($schoolRoute, array_replace($fields, ['participants' => '25']), $schoolRoute . '?new=1'), 'Conflicting token reuse is rejected.');
        $snapshots = $db->query('SELECT * FROM training_request_modules WHERE request_id = ' . $id . ' ORDER BY module_id')->fetchAll();
        expect(count($snapshots) === 2 && str_contains(json_encode($snapshots), 'Classroom') && str_contains(json_encode($snapshots), 'Second available'), 'Package expands into immutable module snapshots.');
        trainingStatus($school->send($detail), 200, 'School can read its request.');
        trainingStatus($browsers['other-school']->send($detail), 404, 'Other schools cannot read the request.');
        trainingStatus($admin->submit($catalogue, array_replace($packageFields, ['id' => $package,
            'revision' => $row('training_packages', $package)['revision'], 'title' => 'Changed package title']), $packagePage), 303, 'Edit live package title.');
        expect($row('training_requests', $id)['package_title'] === 'School development package', 'Existing request retains submitted package title.');
        trainingStatus($guest->send($public . '/package?id=' . $package), 404, 'Editing published package returns it to draft.');
        trainingStatus($admin->submit($catalogue, ['action' => 'publish', 'status' => 'published', 'kind' => 'package', 'id' => $package,
            'revision' => $row('training_packages', $package)['revision']], $packagePage), 303, 'Republish edited package.');
        trainingStatus($admin->submit($catalogue, $moduleFields(['id' => $module, 'revision' => $row('training_modules', $module)['revision'],
            'title' => 'Changed live module']), $modulePage), 303, 'Editing published metadata creates a draft.');
        trainingStatus($guest->send($public . '/module?id=' . $module), 404, 'Editing withdraws module.');
        trainingStatus($guest->send($public . '/package?id=' . $package), 404, 'Package with unavailable module is withdrawn.');
        trainingDenied($school->submit($schoolRoute, $intake(), $schoolRoute . '?new=1'), 'Unavailable module cannot be requested.');
        expect($snapshots === $db->query('SELECT * FROM training_request_modules WHERE request_id = ' . $id . ' ORDER BY module_id')->fetchAll(), 'Catalogue edits preserve request snapshots.');
        $act = static fn (string $action, array $values = []): array => $admin->submit($requests,
            $values + ['action' => $action, 'id' => $id, 'revision' => $row('training_requests', $id)['revision']], $staffPage);
        $schedule = ['starts_at' => date('Y-m-d\TH:i', time() + 86400 * 3), 'ends_at' => date('Y-m-d\TH:i', time() + 86400 * 3 + 7200),
            'delivery_format' => 'hybrid', 'session_location' => 'Private meeting room'];
        foreach (['discussion', 'approve', 'schedule', 'complete'] as $action) trainingDenied($act($action, $schedule), 'Cannot skip Submitted workflow stage.');
        trainingStatus($act('under_review', ['note' => 'Initial review']), 303, 'Submitted moves to Under Review.');
        expect($row('training_requests', $id)['status'] === 'under_review', 'Review action records exact status.');
        trainingDenied($act('discussion', ['revision' => $initial['revision']]), 'Stale decisions are rejected.');
        trainingStatus($act('discussion', ['note' => 'Discuss arrangements']), 303, 'Under Review moves to Discussion.');
        expect($row('training_requests', $id)['status'] === 'discussion', 'Discussion action records exact status.');
        trainingDenied($act('schedule', $schedule), 'Schedule requires assignment and recorded approval.');
        trainingDenied($act('assign', ['assigned_admin_id' => $ids['teacher']]), 'Assignment requires an administrator.');
        trainingStatus($act('assign', ['assigned_admin_id' => $ids['staff']]), 303, 'Assign active administrator.');
        trainingDenied($act('schedule', $schedule), 'Assignment alone is not approval.');
        trainingStatus($act('approve', ['note' => 'School proposal approved']), 303, 'Record approval in Discussion.');
        $approved = $row('training_requests', $id);
        expect($approved['status'] === 'discussion' && (int) $approved['approved_by'] === $ids['staff']
            && $approved['approved_at'] !== null, 'Approval records actor and timestamp while remaining in Discussion.');
        trainingStatus($act('assign', ['assigned_admin_id' => $ids['second-staff']]), 303, 'Reassign before scheduling.');
        trainingDenied($act('schedule', $schedule), 'Reassignment invalidates prior approval.');
        trainingStatus($act('approve', ['note' => 'Reapproved for assignee']), 303, 'Approve reassigned request.');
        $db->prepare("UPDATE school_onboarding SET review_status = 'pending' WHERE user_id = ?")->execute([$ids['school']]);
        trainingDenied($act('schedule', $schedule), 'Scheduling rechecks school approval.');
        trainingStatus($school->send($detail), 200, 'School retains private request access after approval changes.');
        $db->prepare("UPDATE school_onboarding SET review_status = 'approved' WHERE user_id = ?")->execute([$ids['school']]);
        $db->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ids['second-staff']]);
        trainingDenied($act('schedule', $schedule), 'Scheduling rechecks assigned administrator role.');
        $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin'")->execute([$ids['second-staff']]);
        grantTestStaffRole($db, $ids['second-staff']);
        $db->prepare('UPDATE users SET suspended_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$ids['second-staff']]);
        trainingDenied($act('schedule', $schedule), 'Suspended administrators cannot be assigned to a scheduled session.');
        $db->prepare('UPDATE users SET suspended_at = NULL WHERE id = ?')->execute([$ids['second-staff']]);
        trainingDenied($act('schedule', array_replace($schedule, ['ends_at' => $schedule['starts_at']])), 'End must follow start.');
        trainingDenied($act('schedule', array_replace($schedule, ['starts_at' => date('Y-m-d\TH:i', time() - 7200)])), 'Start must be future.');
        trainingStatus($act('schedule', $schedule), 303, 'Schedule approved request.');
        $scheduled = $row('training_requests', $id);
        expect($scheduled['status'] === 'scheduled', 'Schedule action records exact status.');
        expect(str_contains(json_encode($scheduled), gmdate('Y-m-d H:i', strtotime($schedule['starts_at'] . '+01:00'))), 'WAT form time is stored in UTC.');
        trainingDenied($act('complete', ['outcome' => 'Private outcome 842']), 'Cannot complete before session end.');
        trainingDenied($act('reschedule', $schedule), 'Reschedule requires a reason.');
        trainingStatus($act('reschedule', $schedule + ['note' => 'School requested new arrangements']), 303, 'Explicit reschedule records reason.');
        // Move only the fixture clock boundary; the endpoint must still validate outcome and terminal immutability.
        $db->exec('UPDATE training_requests SET scheduled_start = UTC_TIMESTAMP(6) - INTERVAL 2 HOUR, scheduled_end = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR WHERE id = ' . $id);
        trainingDenied($act('complete'), 'Completion requires outcome.');
        trainingStatus($act('complete', ['outcome' => 'Private outcome 842']), 303, 'Complete past session with outcome.');
        $terminal = $row('training_requests', $id);
        expect($terminal['status'] === 'completed' && $terminal['outcome'] === 'Private outcome 842', 'Completion records outcome and exact status.');
        foreach (['under_review', 'discussion', 'approve', 'assign', 'schedule', 'reschedule', 'complete', 'cancel'] as $action) {
            trainingDenied($act($action, $schedule + ['assigned_admin_id' => $ids['staff'], 'note' => 'Terminal probe', 'outcome' => 'Probe']), 'Completed request is immutable.');
        }
        expect($terminal === $row('training_requests', $id), 'Terminal probes cannot mutate request.');
        trainingStatus($school->submit($schoolRoute, $intake(['module_ids' => [$second]]), $schoolRoute . '?new=1'), 303, 'Submit cancellation fixture.');
        $cancelId = (int) $db->query('SELECT MAX(id) FROM training_requests')->fetchColumn();
        $cancel = static fn (array $extra): array => $admin->submit($requests, $extra + ['action' => 'cancel', 'id' => $cancelId,
            'revision' => $row('training_requests', $cancelId)['revision']], $requests . '?id=' . $cancelId);
        trainingDenied($cancel([]), 'Cancellation requires reason.');
        trainingStatus($cancel(['note' => 'School withdrew the request']), 303, 'Cancel active request with reason.');
        expect($row('training_requests', $cancelId)['status'] === 'cancelled', 'Cancellation records exact status.');
        trainingDenied($cancel(['note' => 'Cannot repeat']), 'Cancelled request is immutable.');
        expect((int) $db->query('SELECT COUNT(*) FROM training_request_history WHERE request_id = ' . $id)->fetchColumn() >= 8, 'Attributed request history records decisions.');
        expect((int) $db->query('SELECT COUNT(*) FROM training_request_history WHERE request_id = ' . $id . ' AND actor_id IS NULL')->fetchColumn() === 0,
            'Every submitted decision retains an actor.');
        expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'training.%' AND actor_user_id = " . $ids['staff'])->fetchColumn() > 0,
            'Training decisions create attributed audit events.');
        expect((int) $db->query('SELECT COUNT(*) FROM verification_notifications WHERE user_id = ' . $ids['school'])->fetchColumn() > 0, 'School receives in-app notifications.');
        $mail = $db->query("SELECT payload FROM email_outbox WHERE template = 'training_confirmation'")->fetchAll(PDO::FETCH_COLUMN);
        expect(count($mail) > 0, 'Training events enqueue email.');
        foreach ($mail as $payload) {
            foreach (['Private school requirements 427', 'Private outcome 842', 'Participant workbook description'] as $secret) {
                expect(!str_contains($payload, $secret), 'Email contains generic authenticated links without private content.');
            }
        }
        $messageFields = ['action' => 'create', 'subject' => 'Training question', 'body' => 'Please clarify arrangements.',
            'context_type' => 'training', 'context_id' => $id, 'token' => bin2hex(random_bytes(16))];
        trainingStatus($school->submit('/messages', $messageFields), 303, 'Owner can message about training.');
        foreach (['other-school', 'teacher', 'parent'] as $name) {
            trainingDenied($browsers[$name]->submit('/messages', array_replace($messageFields, ['token' => bin2hex(random_bytes(16))])), 'Training messaging context is restricted to owning school.');
        }
        echo "PASS published metadata, role/approval restrictions, ownership, snapshots and intake idempotency.\n";
        echo "PASS guarded workflow, revision checks, assignment, approval, WAT scheduling, outcomes and terminal immutability.\n";
        echo "PASS attributed history, generic notifications/outbox and private training message contexts.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    foreach ([$work . '/app.log', $work . '/server.log'] as $log) {
        if (is_file($log)) fwrite(STDERR, implode('', array_slice(file($log), -12)));
    }
    exit(1);
}

<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Training\TrainingCatalogue;
use Furoeducare\Modules\Training\TrainingRequests;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/school-fixtures.php';

// Supplemental service checks: run after tests/training.php against its migrated disposable schema.
// No provisioning, migrations, SMTP, HTTP server or broad fixture cleanup occurs here.
try {
    $root = dirname(__DIR__);
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_training_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an existing migrated loopback development schema named furoeducare_test_training_<suffix>.');
    $db = Database::connect($config);
    $runner = new MigrationRunner($db, $root . '/database/migrations');
    $status = $runner->status();
    expect($status !== [] && array_unique(array_values($status)) === ['applied'], 'All current migrations must already be applied.');
    $runner->withCurrentSchema(static function () use ($db): void {
        $suffix = bin2hex(random_bytes(6));
        $prefix = 'zzzz invariant ' . $suffix;
        $password = 'Synthetic invariant fixture 951!';
        $users = Database::transaction($db, static function (PDO $db) use ($suffix, $password): array {
            $users = [];
            foreach (['school' => 'school', 'staff' => 'admin'] as $name => $role) {
                $email = 'training-invariant-' . $suffix . '-' . $name . '@example.test';
                $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
                    ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
                $users[$name] = (int) $db->lastInsertId();
                $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$users[$name], $role]);
                if ($role === 'admin') {
                    grantTestStaffRole($db, $users[$name]);
                }
                if ($role === 'school') {
                    (new SchoolRepository(static fn (): PDO => $db))->create($users[$name], schoolFields($email, $password, [
                        'school_name' => 'Invariant school ' . $suffix, 'city' => 'Invariant city ' . $suffix,
                    ]));
                    $db->prepare("UPDATE school_onboarding SET review_status = 'approved', approved_at = UTC_TIMESTAMP(6) WHERE user_id = ?")
                        ->execute([$users[$name]]);
                }
            }
            return $users;
        });
        // Bulk fixture setup is one transaction; each package has valid ordered published membership.
        [$modules, $packages] = Database::transaction($db, static function (PDO $db) use ($prefix, $users): array {
            $moduleInsert = $db->prepare("INSERT INTO training_modules (title, description, category, objectives, target_participants,
                duration, delivery_format, prerequisites, materials_metadata, status, created_by)
                VALUES (?, 'Public module description', ?, 'Improve teaching', 'Teachers', 'One day', 'on_site', '', 'Public workbook description', 'published', ?)");
            $modules = [];
            for ($index = 0; $index < 202; $index++) {
                $moduleInsert->execute([$prefix . ' module ' . sprintf('%03d', $index), $prefix, $users['staff']]);
                $modules[] = (int) $db->lastInsertId();
            }
            $packageInsert = $db->prepare("INSERT INTO training_packages (title, description, status, created_by) VALUES (?, 'Public package description', 'published', ?)");
            $memberInsert = $db->prepare('INSERT INTO training_package_modules (package_id, module_id, position) VALUES (?, ?, ?)');
            $packages = [];
            for ($index = 0; $index < 202; $index++) {
                $packageInsert->execute([$prefix . ' package ' . sprintf('%03d', $index), $users['staff']]);
                $package = (int) $db->lastInsertId();
                $packages[] = $package;
                if ($index === 0) {
                    $memberInsert->execute([$package, $modules[1], 1]);
                    $memberInsert->execute([$package, $modules[0], 2]);
                } else {
                    $memberInsert->execute([$package, $modules[201], 1]);
                }
            }
            return [$modules, $packages];
        });
        $catalogue = new TrainingCatalogue(static fn (): PDO => $db);
        $requests = new TrainingRequests(static fn (): PDO => $db);
        $counts = static function () use ($db): array {
            $result = [];
            foreach (['training_requests', 'training_request_modules', 'training_request_history', 'verification_notifications', 'audit_logs', 'email_outbox'] as $table) {
                $result[$table] = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
            }
            return $result;
        };
        $input = ['participants' => '20', 'location' => 'Invariant school hall',
            'preferred_dates' => (new DateTimeImmutable('+20 days', new DateTimeZone('Africa/Lagos')))->format('Y-m-d'),
            'requirements' => 'Private invariant requirements'];
        $token = bin2hex(random_bytes(16));
        $id = $requests->submit($users['school'], $input, [], $packages[0], $token);
        $detail = $requests->find($users['school'], false, $id);
        expect(array_map('intval', array_column($detail['modules'], 'module_id')) === [$modules[1], $modules[0]], 'Submitted package preserves higher-ID then lower-ID module order.');
        expect(array_column(array_column($detail['modules'], 'snapshot'), 'selection_position') === [1, 2], 'Snapshots retain their selection positions.');
        $db->prepare("UPDATE training_modules SET status = 'draft', title = 'Withdrawn changed invariant module', revision = revision + 1 WHERE id = ?")->execute([$modules[0]]);
        $beforeReplay = $counts();
        expect($requests->submit($users['school'], $input, [], $packages[0], $token) === $id, 'Exact retry returns the original request after catalogue withdrawal.');
        expect($counts() === $beforeReplay, 'Retry creates no requests, snapshots, history, notifications, audit or emails.');
        expect($requests->find($users['school'], false, $id)['modules'] === $detail['modules'], 'Retry preserves original submitted snapshots.');
        try {
            $requests->submit($users['school'], array_replace($input, ['participants' => '21']), [], $packages[0], $token);
            throw new RuntimeException('Conflicting intake token was accepted.');
        } catch (DomainException $error) {
            expect($error->getCode() === 409, 'Changed token payload returns a conflict.');
        }
        expect($counts() === $beforeReplay, 'Conflicting token reuse cannot write any business or notification data.');

        $trigger = 'training_invariant_fail_' . $suffix;
        $triggerCreated = false;
        try {
            $db->exec("CREATE TRIGGER `$trigger` BEFORE INSERT ON email_outbox FOR EACH ROW BEGIN
                IF NEW.template = 'training_confirmation' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'training invariant forced outbox failure';
                END IF;
            END");
            $triggerCreated = true;
            $beforeFailure = $counts();
            try {
                $requests->submit($users['school'], $input, [$modules[201]], null, bin2hex(random_bytes(16)));
                throw new RuntimeException('Intake survived a forced outbox failure.');
            } catch (PDOException $error) {
                expect(str_contains($error->getMessage(), 'training invariant forced outbox failure'), 'Intake failed at the intentional outbox boundary.');
            }
            expect(!$db->inTransaction() && $counts() === $beforeFailure, 'Failed intake rolls back request, snapshots, history, in-app notification, audit and outbox.');
            $beforeDecision = $requests->find($users['staff'], true, $id);
            try {
                $requests->change($users['staff'], $id, (int) $beforeDecision['revision'], 'under_review', ['note' => 'Forced failure decision']);
                throw new RuntimeException('Staff decision survived a forced outbox failure.');
            } catch (PDOException $error) {
                expect(str_contains($error->getMessage(), 'training invariant forced outbox failure'), 'Staff decision failed at the intentional outbox boundary.');
            }
            expect(!$db->inTransaction() && $counts() === $beforeFailure, 'Failed decision leaves all business, history, notification, audit and outbox counts unchanged.');
            expect($requests->find($users['staff'], true, $id) === $beforeDecision, 'Failed decision preserves status, revision, timestamps and complete request detail.');
        } finally {
            if ($triggerCreated) {
                $db->exec("DROP TRIGGER `$trigger`");
            }
        }

        $tailModule = $modules[201];
        $tailPackage = $packages[201];
        $default = $catalogue->choices();
        expect(!in_array($tailModule, array_map('intval', array_column($default['modules'], 'id')), true)
            && !in_array($tailPackage, array_map('intval', array_column($default['packages'], 'id')), true), 'Tail fixtures lie outside the default 200-choice window.');
        $selected = $catalogue->choices([$tailModule], $tailPackage);
        expect(in_array($tailModule, array_map('intval', array_column($selected['modules'], 'id')), true)
            && in_array($tailPackage, array_map('intval', array_column($selected['packages'], 'id')), true), 'Selected published module and package beyond 200 remain available to the request form.');
        $private = $catalogue->choices([$modules[0]], $packages[0]);
        expect(!in_array($modules[0], array_map('intval', array_column($private['modules'], 'id')), true)
            && !in_array($packages[0], array_map('intval', array_column($private['packages'], 'id')), true), 'Selected extras never expose draft modules or unavailable packages.');

        $first = $catalogue->browse($prefix, PHP_INT_MAX, PHP_INT_MAX);
        expect(count($first['modules']) === 13 && count($first['packages']) === 13, 'Pagination fixtures exceed both catalogue page sizes.');
        $moduleIds = array_map('intval', array_column(array_slice($first['modules'], 0, 12), 'id'));
        $packageIds = array_map('intval', array_column(array_slice($first['packages'], 0, 12), 'id'));
        $moduleCursor = $moduleIds[11];
        $packageCursor = $packageIds[11];
        $moduleNext = $catalogue->browse($prefix, $moduleCursor, PHP_INT_MAX);
        expect(array_intersect($moduleIds, array_column(array_slice($moduleNext['modules'], 0, 12), 'id')) === [], 'Advancing modules never repeats previous module cards.');
        expect($moduleNext['packages'] === $first['packages'], 'Advancing modules preserves the independent package page.');
        $packageNext = $catalogue->browse($prefix, PHP_INT_MAX, $packageCursor);
        expect(array_intersect($packageIds, array_column(array_slice($packageNext['packages'], 0, 12), 'id')) === [], 'Advancing packages never repeats previous package cards.');
        expect($packageNext['modules'] === $first['modules'], 'Advancing packages preserves the independent module page.');

        echo "PASS submitted package order, catalogue-withdrawal retries and conflicting token rollback.\n";
        echo "PASS forced outbox failure rolls back intake and staff decisions atomically.\n";
        echo "PASS selected choices beyond 200, draft exclusion and independent catalogue cursors.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    exit(1);
}

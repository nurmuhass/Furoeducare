<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejectSql(PDO $connection, string $sql, int $code): void
{
    try {
        $connection->exec($sql);
    } catch (PDOException $exception) {
        expect(($exception->errorInfo[1] ?? null) === $code, "Expected MySQL error $code.");
        return;
    }
    throw new RuntimeException("Expected MySQL error $code, but the write succeeded.");
}

function rejectOperation(callable $operation, string $reason): void
{
    try {
        $operation();
    } catch (RuntimeException|LogicException $exception) {
        expect(str_contains($exception->getMessage(), $reason), "Expected refusal containing '$reason'.");
        return;
    }
    throw new RuntimeException("Expected refusal containing '$reason', but the operation succeeded.");
}

try {
    expect(class_exists(MigrationRunner::class), 'The migration runner must be available through Composer.');
    $root = dirname(__DIR__);
    $config = Config::load($root);
    expect(
        $config->environment === 'development'
        && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use a disposable loopback development database named furoeducare_test_<suffix>.'
    );
    $db = Database::connect($config);
    expect(str_starts_with((string) $db->query('SELECT VERSION()')->fetchColumn(), '8.4.'), 'Use MySQL 8.4 LTS.');
    expect($db->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false, 'Use native prepared statements.');
    expect($db->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00', 'Use UTC for this connection.');
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'The disposable database must be empty.');

    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $seed = new SeedRunner($db, $root . '/database/seeders');
    expect(count($migrations->status()) === 52 && array_values(array_unique($migrations->status())) === ['pending'], 'Initial migrations must be pending.');
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Status must leave a fresh database empty.');
    rejectOperation(fn () => $migrations->withCurrentSchema($seed->run(...)), 'pending');
    $previous = sys_get_temp_dir() . '/furoeducare-migrations-0034-' . bin2hex(random_bytes(6));
    $previousFiles = array_filter(glob($root . '/database/migrations/*.php'),
        static fn (string $file): bool => (int) substr(basename($file), 0, 4) <= 34);
    expect(mkdir($previous, 0700), 'Cannot create the previous-release migration directory.');
    try {
        foreach ($previousFiles as $file) {
            expect(copy($file, $previous . '/' . basename($file)), 'Cannot copy the previous-release migration.');
        }
        $previousRelease = new MigrationRunner($db, $previous);
        expect(count($previousRelease->migrate()) === 34, 'The previous release must apply on a fresh schema.');
        $previousReversible = array_slice(array_keys($previousRelease->status()), -9);
        foreach (array_reverse($previousReversible) as $version) {
            expect($previousRelease->rollback() === $version, 'Previous-release vacancies and their dependencies roll back in reverse order.');
        }
        rejectOperation($previousRelease->rollback(...), 'has no rollback');
        expect($previousRelease->migrate() === $previousReversible, 'The previous reversible migrations can be reapplied.');
    } finally {
        foreach (glob($previous . '/*.php') as $file) {
            unlink($file);
        }
        rmdir($previous);
    }
    $db->exec("INSERT INTO users (id, email, password_hash) VALUES
        (101, 'legacy-school@example.test', 'fixture'),
        (102, 'legacy-candidate@example.test', 'fixture'),
        (103, 'legacy-second@example.test', 'fixture'),
        (104, 'legacy-parent@example.test', 'fixture')");
    $db->exec("INSERT INTO school_onboarding (user_id, school_name) VALUES (101, 'Legacy School')");
    $db->exec("INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone, terms_version, privacy_version, accepted_at, sections)
        VALUES (102, 'teacher', 'Legacy', 'Teacher', '+2348012345678', 'fixture', 'fixture', NOW(6), JSON_OBJECT()),
            (103, 'teacher', 'Legacy', 'Candidate', '+2348012345678', 'fixture', 'fixture', NOW(6), JSON_OBJECT())");
    $db->exec("INSERT INTO vacancies (id, school_id, status, content) VALUES (101, 101, 'closed', JSON_OBJECT())");
    $db->exec("INSERT INTO vacancy_applications (id, vacancy_id, teacher_id, status, vacancy_revision, job_title, job_snapshot, updated_at)
        VALUES (201, 101, 102, 'rejected', 1, 'Legacy vacancy', JSON_OBJECT(), '2024-04-01 10:00:00.000000'),
            (202, 101, 103, 'submitted', 1, 'Legacy vacancy', JSON_OBJECT(), '2024-04-01 11:00:00.000000')");
    $db->exec("INSERT INTO vacancy_application_history (application_id, from_status, to_status, note)
        VALUES (201, 'under_review', 'rejected', 'Keep this historical decision.')");
    $db->exec("INSERT INTO parent_profiles (user_id, first_name, last_name, phone, state_code, city, terms_version, privacy_version, accepted_at)
        VALUES (104, 'Legacy', 'Parent', '+2348012345678', 'LA', 'Ikeja', 'fixture', 'fixture', NOW(6))");
    $db->exec("INSERT INTO parent_tutoring_requests (parent_id, subject, requirements, submitted_at, updated_at) VALUES
        (104, 'Legacy general draft', 'Keep this draft', NULL, '2024-05-01 10:00:00.000000'),
        (104, 'Legacy general submission', 'Keep this submission', '2024-05-02 10:00:00.000000', '2024-05-02 10:00:00.000000')");
    $db->exec("INSERT INTO teacher_listings (id, teacher_id, reference, title, bio, qualification, experience, state_code, city,
        availability_mask, approval_basis) VALUES (401, 102, 'FT-0123456789ABCDEF0123', 'Legacy tutor', '', 'B.Ed', 2, 'LA', 'Ikeja', 4, 'fixture')");
    $db->exec("INSERT INTO teacher_listing_requests (id, requester_id, listing_id, requester_role, notes, submitted_at) VALUES
        (501, 104, 401, 'parent', 'Legacy directory tutoring request', '2024-05-03 10:00:00.000000'),
        (502, 101, 401, 'school', 'Legacy school enquiry', '2024-05-03 12:00:00.000000')");
    expect(count($migrations->migrate()) === 18, 'The application, tutoring and disclosure migrations must upgrade the previous release.');
    $tutoring = $db->query('SELECT subject, requirements, status, updated_at, selected_teacher_reference, legacy_directory_id
        FROM parent_tutoring_requests WHERE parent_id = 104 ORDER BY id')->fetchAll();
    expect(array_column($tutoring, 'status') === ['draft', 'submitted', 'submitted']
        && $tutoring[1]['updated_at'] === '2024-05-02 10:00:00.000000'
        && $tutoring[2]['requirements'] === 'Legacy directory tutoring request'
        && $tutoring[2]['selected_teacher_reference'] === 'FT-0123456789ABCDEF0123'
        && (int) $tutoring[2]['legacy_directory_id'] === 501,
        'Upgrade preserves general drafts, prior submissions and selected-teacher enquiries.');
    expect((int) $db->query("SELECT COUNT(*) FROM parent_tutoring_requests WHERE legacy_directory_id = 502")->fetchColumn() === 0,
        'School enquiries stay in the school workflow.');
    echo "PASS tutoring upgrade preserves general and specific-teacher requests and excludes school enquiries.\n";
    $cases = $db->query('SELECT requester_id, requester_role, category, selected_reference, tutoring_id, legacy_directory_id, status
        FROM candidate_requests ORDER BY id')->fetchAll();
    expect(count($cases) === 3 && $cases[0]['requester_role'] === 'school' && (int) $cases[0]['legacy_directory_id'] === 502
        && $cases[0]['selected_reference'] === 'FT-0123456789ABCDEF0123'
        && $cases[1]['requester_role'] === 'parent' && (int) $cases[1]['requester_id'] === 104
        && $cases[1]['tutoring_id'] !== null && $cases[2]['selected_reference'] === 'FT-0123456789ABCDEF0123',
        'The central queue preserves submitted tutoring and school enquiries with their owning accounts and references.');
    expect((int) $db->query('SELECT COUNT(*) FROM candidate_disclosure_grants')->fetchColumn() === 0,
        'Migrating requests cannot grant access to restricted information.');
    echo "PASS central candidate request backfill and default absence of disclosure grants.\n";
    $upgraded = $db->query('SELECT status, candidate_reference, application_statement, profile_snapshot, updated_at FROM vacancy_applications ORDER BY id')->fetchAll();
    expect(array_column($upgraded, 'status') === ['unsuccessful', 'submitted']
        && count(array_unique(array_column($upgraded, 'candidate_reference'))) === 2
        && preg_match('/\ACA-[0-9A-F]{32}\z/', $upgraded[0]['candidate_reference']) === 1
        && preg_match('/\ACA-[0-9A-F]{32}\z/', $upgraded[1]['candidate_reference']) === 1
        && $upgraded[0]['profile_snapshot'] === null && $upgraded[0]['application_statement'] === ''
        && $upgraded[0]['updated_at'] === '2024-04-01 10:00:00.000000',
        'Existing applications receive distinct references and keep their original data.');
    expect($db->query("SELECT to_status FROM vacancy_application_history WHERE application_id = 201")->fetchColumn() === 'rejected',
        'Historical status events are preserved.');
    echo "PASS previous-release application upgrade, unique references and legacy history.\n";
    expect($migrations->migrate() === [], 'A repeated migration must do nothing.');
    expect(array_values(array_unique($migrations->status())) === ['applied'], 'All 52 migrations must be recorded.');
    $tables = $db->query(
        'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll();
    expect(array_column($tables, 'TABLE_NAME') === [
        'audit_logs', 'auth_rate_limits', 'candidate_disclosure_downloads', 'candidate_disclosure_grants', 'candidate_disclosure_requests',
        'candidate_privacy_requests', 'candidate_requests', 'candidate_shortlists', 'email_verification_tokens', 'parent_profiles',
        'parent_tutoring_requests', 'password_reset_tokens', 'roles', 'saved_jobs', 'saved_teachers',
        'schema_migrations', 'school_onboarding', 'school_recruitment_drafts', 'school_reviews', 'system_settings', 'teacher_cv_extractions',
        'teacher_disclosure_preferences', 'teacher_document_reviews', 'teacher_documents', 'teacher_experiences', 'teacher_listing_requests',
        'teacher_listing_tags', 'teacher_listings', 'teacher_profiles',
        'teacher_qualifications', 'teacher_verification_reviews', 'tutoring_candidates', 'tutoring_request_history', 'user_roles', 'users',
        'vacancies', 'vacancy_application_history', 'vacancy_applications', 'vacancy_history', 'verification_notifications',
    ], 'Only the expected tables and migration ledger should exist.');
    expect(array_unique(array_column($tables, 'ENGINE')) === ['InnoDB'], 'All tables must use InnoDB.');
    expect(array_unique(array_column($tables, 'TABLE_COLLATION')) === ['utf8mb4_0900_ai_ci'], 'All tables must default to utf8mb4.');
    echo "PASS empty-schema bootstrap, status, encoding, and migration idempotency.\n";

    $migrations->withCurrentSchema($seed->run(...));
    $db->exec("UPDATE roles SET name = 'Teacher label' WHERE slug = 'teacher'");
    $migrations->withCurrentSchema($seed->run(...));
    $roles = $db->query('SELECT slug, name FROM roles ORDER BY slug')->fetchAll(PDO::FETCH_KEY_PAIR);
    expect(array_keys($roles) === ['admin', 'parent', 'school', 'teacher'], 'Exactly the four reference roles must exist.');
    expect($roles['teacher'] === 'Teacher label', 'Reseeding must preserve an existing role.');
    $db->exec("UPDATE roles SET name = 'Teacher' WHERE slug = 'teacher'");
    echo "PASS transactional and repeatable reference-role seeding.\n";

    Database::transaction($db, function (PDO $db): void {
        $db->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute([
            'teacher@example.test', password_hash('fixture-password', PASSWORD_DEFAULT),
        ]);
    });
    $userId = (int) $db->query("SELECT id FROM users WHERE email = 'teacher@example.test'")->fetchColumn();
    expect($userId > 0, 'The inserted user must commit.');
    rejectOperation(function () use ($db): void {
        Database::transaction($db, function (PDO $db): void {
            $db->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('test.rollback', 'true')");
            throw new RuntimeException('fixture rollback');
        });
    }, 'fixture rollback');
    expect((int) $db->query('SELECT COUNT(*) FROM system_settings')->fetchColumn() === 0, 'Failed work must be rolled back.');
    rejectOperation(
        fn () => Database::transaction($db, fn (PDO $db) => Database::transaction($db, fn () => null)),
        'already active'
    );
    expect(!$db->inTransaction(), 'A nested transaction failure must leave the connection usable.');
    echo "PASS PDO transaction commit, rollback, and nested transaction rejection.\n";

    rejectSql($db, "INSERT INTO users (email, password_hash) VALUES ('TEACHER@example.test', 'fixture')", 1062);
    $roleId = (int) $db->query("SELECT id FROM roles WHERE slug = 'teacher'")->fetchColumn();
    $db->exec("INSERT INTO user_roles (user_id, role_id) VALUES ($userId, $roleId)");
    rejectSql($db, "INSERT INTO user_roles (user_id, role_id) VALUES ($userId, $roleId)", 1062);
    rejectSql($db, "INSERT INTO user_roles (user_id, role_id) VALUES (999999, $roleId)", 1452);
    rejectSql($db, "DELETE FROM roles WHERE id = $roleId", 1451);
    $db->exec("INSERT INTO password_reset_tokens (token_hash, user_id, expires_at)
        VALUES (UNHEX(SHA2('sample-reset', 256)), $userId, CURRENT_TIMESTAMP(6) + INTERVAL 1 HOUR)");
    rejectSql($db, "INSERT INTO password_reset_tokens (token_hash, user_id, expires_at)
        VALUES (UNHEX(SHA2('sample-reset', 256)), $userId, CURRENT_TIMESTAMP(6) + INTERVAL 1 HOUR)", 1062);
    $db->exec("INSERT INTO email_verification_tokens (token_hash, user_id, email_at_issue, expires_at)
        VALUES (UNHEX(SHA2('sample-verify', 256)), $userId, 'teacher@example.test', CURRENT_TIMESTAMP(6) + INTERVAL 1 HOUR)");
    $db->exec("INSERT INTO audit_logs (actor_user_id, action) VALUES ($userId, 'test.account')");
    $db->exec("DELETE FROM users WHERE id = $userId");
    foreach (['user_roles', 'password_reset_tokens', 'email_verification_tokens'] as $table) {
        expect((int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 0, "Deleting a user must clear $table.");
    }
    expect((int) $db->query('SELECT COUNT(*) FROM audit_logs WHERE actor_user_id IS NULL')->fetchColumn() === 1, 'The audit event must survive.');
    $db->exec("DELETE FROM audit_logs WHERE action = 'test.account'");
    $db->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('test.clock', 'true')");
    $before = $db->query("SELECT created_at, updated_at FROM system_settings WHERE setting_key = 'test.clock'")->fetch();
    usleep(10_000);
    $db->exec("UPDATE system_settings SET setting_value = 'false' WHERE setting_key = 'test.clock'");
    $after = $db->query("SELECT created_at, updated_at FROM system_settings WHERE setting_key = 'test.clock'")->fetch();
    expect($before['created_at'] === $after['created_at'] && $before['updated_at'] < $after['updated_at'], 'Only updated_at must advance.');
    expect($db->query("SHOW COLUMNS FROM teacher_profiles LIKE 'professional_verified_at'")->fetch()['Null'] === 'YES', 'Professional verification must be explicitly nullable.');
    echo "PASS unique identifiers, foreign key actions, and timestamps.\n";

    $reversibleMigrations = [
        '0047_create_candidate_shortlists', '0048_create_candidate_disclosure_requests', '0049_create_candidate_disclosure_grants',
        '0050_create_candidate_disclosure_downloads', '0051_create_teacher_disclosure_preferences', '0052_create_candidate_privacy_requests',
    ];
    foreach (array_reverse($reversibleMigrations) as $version) {
        expect($migrations->rollback() === $version, 'Dependent migrations roll back in reverse order.');
    }
    rejectOperation($migrations->rollback(...), 'has no rollback');
    expect($migrations->migrate() === $reversibleMigrations, 'Reversible migrations can be reapplied.');
    $migrations->withCurrentSchema($seed->run(...));

    $seedScratch = sys_get_temp_dir() . '/furoeducare-seeders-' . bin2hex(random_bytes(6));
    expect(mkdir($seedScratch, 0700), 'Cannot create temporary seed directory.');
    $seedFirst = $seedScratch . '/0001_partial.php';
    $seedFailure = $seedScratch . '/0002_failure.php';
    try {
        file_put_contents($seedFirst, <<<'PHP'
            <?php return static function (PDO $db): void {
                $db->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('test.partial_seed', 'true')");
            };
            PHP);
        file_put_contents($seedFailure, <<<'PHP'
            <?php return static function (PDO $db): void {
                throw new RuntimeException('fixture seed failure');
            };
            PHP);
        $failingSeed = new SeedRunner($db, $seedScratch);
        rejectOperation(fn () => $migrations->withCurrentSchema($failingSeed->run(...)), 'fixture seed failure');
        $partial = $db->query("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'test.partial_seed'")->fetchColumn();
        expect((int) $partial === 0, 'A failed seed run must roll back earlier seed writes.');
    } finally {
        unlink($seedFirst);
        unlink($seedFailure);
        rmdir($seedScratch);
    }
    echo "PASS failed seed run rolls back earlier seed writes.\n";

    $scratch = sys_get_temp_dir() . '/furoeducare-migrations-' . bin2hex(random_bytes(6));
    expect(mkdir($scratch, 0700), 'Cannot create temporary migrations directory.');
    try {
        foreach (glob($root . '/database/migrations/*.php') as $file) {
            copy($file, $scratch . '/' . basename($file));
        }
        $fixture = $scratch . '/0053_fixture.php';
        $reversible = "<?php return ['up' => 'CREATE TABLE rollback_fixture (id INT PRIMARY KEY) ENGINE=InnoDB', 'down' => 'DROP TABLE rollback_fixture'];\n";
        file_put_contents($fixture, $reversible);
        $broken = new MigrationRunner($db, $scratch);
        expect($broken->migrate() === ['0053_fixture'], 'A reversible migration must apply.');
        expect($broken->rollback() === '0053_fixture', 'Rollback must reverse the latest reversible migration.');
        expect($broken->migrate() === ['0053_fixture'], 'A clean rollback must support reapplication.');
        expect($broken->rollback() === '0053_fixture', 'The reapplied migration must remain reversible.');
        echo "PASS rollback, irreversible migration protection, and reapplication.\n";

        file_put_contents($fixture, "<?php return ['up' => 'CREATE TABLE', 'down' => null];\n");
        rejectOperation($broken->migrate(...), '0053_fixture');
        expect($broken->status()['0053_fixture'] === 'applying', 'Failed DDL must leave a durable applying marker.');
        rejectOperation($broken->migrate(...), 'applying');
        rejectOperation($broken->rollback(...), 'applying');
        $db->exec("DELETE FROM schema_migrations WHERE version = '0053_fixture'");
        file_put_contents($fixture, "<?php return ['up' => 'CREATE TABLE rollback_fixture (id INT PRIMARY KEY) ENGINE=InnoDB', 'down' => 'DROP TABLE'];\n");
        expect($broken->migrate() === ['0053_fixture'], 'The rollback fixture must apply.');
        rejectOperation($broken->rollback(...), '0053_fixture');
        expect($broken->status()['0053_fixture'] === 'rolling_back', 'Failed rollback must leave a durable marker.');
        rejectOperation($broken->migrate(...), 'rolling_back');
        $db->exec('DROP TABLE rollback_fixture');
        $db->exec("DELETE FROM schema_migrations WHERE version = '0053_fixture'");
        unlink($fixture);
        $first = glob($scratch . '/0001_*.php')[0];
        file_put_contents($first, "\n// changed migration\n", FILE_APPEND);
        expect($broken->status()[basename($first, '.php')] === 'modified', 'Status must expose changed history.');
        rejectOperation($broken->migrate(...), 'modified');
    } finally {
        foreach (glob($scratch . '/*.php') as $file) {
            unlink($file);
        }
        rmdir($scratch);
    }
    echo "PASS failed apply and rollback markers, and checksum drift detection.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

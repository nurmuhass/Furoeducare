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
    expect(count($migrations->status()) === 14 && array_values(array_unique($migrations->status())) === ['pending'], 'Initial migrations must be pending.');
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Status must leave a fresh database empty.');
    rejectOperation(fn () => $migrations->withCurrentSchema($seed->run(...)), 'pending');
    expect(count($migrations->migrate()) === 14, 'All fourteen migrations must apply on the fresh schema.');
    expect($migrations->migrate() === [], 'A repeated migration must do nothing.');
    expect(array_values(array_unique($migrations->status())) === ['applied'], 'All fourteen migrations must be recorded.');
    $tables = $db->query(
        'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll();
    expect(array_column($tables, 'TABLE_NAME') === [
        'audit_logs', 'auth_rate_limits', 'email_verification_tokens', 'password_reset_tokens', 'roles',
        'schema_migrations', 'school_onboarding', 'system_settings', 'teacher_documents', 'teacher_experiences', 'teacher_profiles',
        'teacher_qualifications', 'user_roles', 'users',
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
    echo "PASS unique identifiers, foreign key actions, and timestamps.\n";

    rejectOperation($migrations->rollback(...), 'has no rollback');
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
        $fixture = $scratch . '/0015_fixture.php';
        $reversible = "<?php return ['up' => 'CREATE TABLE rollback_fixture (id INT PRIMARY KEY) ENGINE=InnoDB', 'down' => 'DROP TABLE rollback_fixture'];\n";
        file_put_contents($fixture, $reversible);
        $broken = new MigrationRunner($db, $scratch);
        expect($broken->migrate() === ['0015_fixture'], 'A reversible migration must apply.');
        expect($broken->rollback() === '0015_fixture', 'Rollback must reverse the latest reversible migration.');
        expect($broken->migrate() === ['0015_fixture'], 'A clean rollback must support reapplication.');
        expect($broken->rollback() === '0015_fixture', 'The reapplied migration must remain reversible.');
        echo "PASS rollback, irreversible migration protection, and reapplication.\n";

        file_put_contents($fixture, "<?php return ['up' => 'CREATE TABLE', 'down' => null];\n");
        rejectOperation($broken->migrate(...), '0015_fixture');
        expect($broken->status()['0015_fixture'] === 'applying', 'Failed DDL must leave a durable applying marker.');
        rejectOperation($broken->migrate(...), 'applying');
        rejectOperation($broken->rollback(...), 'applying');
        $db->exec("DELETE FROM schema_migrations WHERE version = '0015_fixture'");
        file_put_contents($fixture, "<?php return ['up' => 'CREATE TABLE rollback_fixture (id INT PRIMARY KEY) ENGINE=InnoDB', 'down' => 'DROP TABLE'];\n");
        expect($broken->migrate() === ['0015_fixture'], 'The rollback fixture must apply.');
        rejectOperation($broken->rollback(...), '0015_fixture');
        expect($broken->status()['0015_fixture'] === 'rolling_back', 'Failed rollback must leave a durable marker.');
        rejectOperation($broken->migrate(...), 'rolling_back');
        $db->exec('DROP TABLE rollback_fixture');
        $db->exec("DELETE FROM schema_migrations WHERE version = '0015_fixture'");
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

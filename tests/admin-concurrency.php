<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Admin\StaffAccess;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

$first = null;
$second = null;
$fixture = 0;
try {
    $config = Config::load(dirname(__DIR__));
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_admin_[a-f0-9]{12}\z/D', $config->databaseName) === 1,
        'Use the existing isolated administration HTTP test schema.');
    $first = Database::connect($config);
    $second = Database::connect($config);
    expect((int) $first->query("SELECT COUNT(*) FROM users WHERE email = 'super@example.test'")->fetchColumn() === 1,
        'Require the main administration fixture marker.');
    $supers = $first->query("SELECT u.id FROM staff_user_roles ur JOIN staff_roles sr ON sr.id = ur.role_id
        JOIN users u ON u.id = ur.user_id WHERE sr.slug = 'super_admin' AND u.suspended_at IS NULL AND u.email_verified_at IS NOT NULL
        AND EXISTS (SELECT 1 FROM user_roles a JOIN roles r ON r.id = a.role_id WHERE a.user_id = u.id AND r.slug = 'admin')")->fetchAll(PDO::FETCH_COLUMN);
    expect(count($supers) === 1, 'Start with exactly one active fixture Super Admin.');
    $remaining = (int) $supers[0];
    $first->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
        ->execute(['concurrency-' . bin2hex(random_bytes(6)) . '@example.test', '!non-login-fixture']);
    $fixture = (int) $first->lastInsertId();
    $first->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin'")->execute([$fixture]);
    $first->prepare("INSERT INTO staff_user_roles (user_id, role_id) SELECT ?, id FROM staff_roles WHERE slug = 'super_admin'")->execute([$fixture]);

    $first->beginTransaction();
    StaffAccess::lockRoles($first);
    $second->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $second->beginTransaction();
    try {
        StaffAccess::lockRoles($second);
        throw new RuntimeException('A competing role transaction bypassed the coordination lock.');
    } catch (PDOException $error) {
        expect(($error->errorInfo[1] ?? null) === 1205, 'The second connection must wait for the first role transaction.');
    } finally {
        if ($second->inTransaction()) $second->rollBack();
    }

    StaffAccess::protectLastSuper($first, $fixture);
    $first->prepare("DELETE ur FROM staff_user_roles ur JOIN staff_roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = 'super_admin'")
        ->execute([$fixture]);
    $first->commit();

    $second->beginTransaction();
    try {
        StaffAccess::protectLastSuper($second, $remaining);
        throw new RuntimeException('The next transaction failed to protect the remaining Super Admin.');
    } catch (DomainException $error) {
        expect($error->getCode() === 409, 'The serialized follow-up transaction must observe the last active Super Admin.');
    } finally {
        if ($second->inTransaction()) $second->rollBack();
    }
    expect((new StaffAccess(static fn (): PDO => $first))->has($remaining, 'staff.manage'), 'The original Super Admin retains management access.');
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    foreach ([$first, $second] as $connection) {
        if ($connection instanceof PDO && $connection->inTransaction()) $connection->rollBack();
    }
    if ($fixture > 0 && $first instanceof PDO) {
        $first->prepare('DELETE FROM users WHERE id = ?')->execute([$fixture]);
    }
}
if ($failed ?? false) exit(1);
echo "PASS competing role transactions serialize and the next decision protects the remaining Super Admin.\n";

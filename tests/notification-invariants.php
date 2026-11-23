<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Notification\NotificationEvents;
use Furoeducare\Modules\Notification\NotificationPreferences;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

// Supplemental tests run after notifications.php on its existing disposable schema.
// Two sequential connections reproduce snapshot interleavings without child processes.
$first = null;
$second = null;
try {
    $config = Config::load(dirname(__DIR__));
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_notifications_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an already migrated disposable loopback furoeducare_test_notifications_<suffix> schema.');
    $first = Database::connect($config);
    $second = Database::connect($config);
    foreach ([$first, $second] as $connection) {
        $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->exec('SET SESSION innodb_lock_wait_timeout = 3');
    }
    expect($second->query("SHOW COLUMNS FROM verification_notifications LIKE 'in_app_visible'")->fetch() !== false,
        'The existing schema includes the central notification migrations.');
    $suffix = bin2hex(random_bytes(8));
    $second->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
        ->execute(['notification-invariants-' . $suffix . '@example.test', '!notification-invariants']);
    $owner = (int) $second->lastInsertId();
    $role = $second->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'teacher'");
    $role->execute([$owner]);
    expect($role->rowCount() === 1, 'The disposable schema has seeded account roles.');
    $preferencesFirst = new NotificationPreferences(static fn (): PDO => $first);
    $preferencesSecond = new NotificationPreferences(static fn (): PDO => $second);
    $all = array_keys(NotificationPreferences::CATEGORIES);
    $preferencesSecond->save($owner, $all, $all);
    $emit = static fn (PDO $db, string $key): int => NotificationEvents::emit($db, $owner, $owner,
        'test.snapshot', $key, 'recruitment', 'Snapshot fixture', 'A synthetic recruitment update is available.',
        '/dashboard/teacher', 'recruitment_update', ['path' => '/dashboard/teacher']);
    $mailCount = static function () use ($second, $owner): int {
        $query = $second->prepare('SELECT COUNT(*) FROM email_outbox WHERE user_id = ?');
        $query->execute([$owner]);
        return (int) $query->fetchColumn();
    };

    $first->beginTransaction();
    $snapshot = NotificationPreferences::read($first, $owner);
    expect($snapshot['in_app']['recruitment'] && $snapshot['email']['recruitment'], 'Establish an enabled preference snapshot.');
    $preferencesSecond->save($owner, [], []);
    $notification = $emit($first, 'invariants:optout:' . $suffix);
    $first->commit();
    $query = $second->prepare('SELECT in_app_visible FROM verification_notifications WHERE id = ? AND user_id = ?');
    $query->execute([$notification, $owner]);
    expect((int) $query->fetchColumn() === 0 && $mailCount() === 0,
        'An older repeatable-read transaction observes a subsequently committed opt-out for both emission decisions.');
    echo "PASS event emission reads current preferences after another connection commits an opt-out.\n";

    $preferencesSecond->save($owner, $all, $all);
    $staleForm = $preferencesFirst->forUser($owner);
    expect($staleForm['in_app']['recruitment'], 'A legacy email form was opened with an earlier in-app choice.');
    $preferencesSecond->save($owner, ['messages'], ['account']);
    $preferencesFirst->saveEmail($owner, ['recruitment']);
    $current = $preferencesSecond->forUser($owner);
    foreach ($all as $category) {
        expect($current['in_app'][$category] === ($category === 'messages'),
            'Email-only save preserves the latest independent in-app choice for ' . $category . '.');
        expect($current['email'][$category] === ($category === 'recruitment'),
            'Email-only save applies the requested email categories.');
    }
    echo "PASS legacy email-only save preserves newer in-app settings from another connection.\n";

    $preferencesSecond->save($owner, $all, $all);
    $beforeMail = $mailCount();
    $first->beginTransaction();
    $query = $first->prepare('SELECT COUNT(*) FROM verification_notifications WHERE user_id = ?');
    $query->execute([$owner]);
    $query->fetchColumn(); // Establish a snapshot before the other connection inserts the source event.
    $key = 'invariants:duplicate:' . $suffix;
    $original = $emit($second, $key);
    $retry = $emit($first, $key);
    $first->commit();
    $query = $second->prepare('SELECT COUNT(*) FROM verification_notifications WHERE user_id = ? AND event_key = ?');
    $query->execute([$owner, hash('sha256', $key)]);
    expect($retry === $original && (int) $query->fetchColumn() === 1 && $mailCount() === $beforeMail + 1,
        'A repeatable-read snapshot predating another connection emission still resolves an identical retry to one ledger and email row.');
    echo "PASS an identical retry resolves a source event committed after its transaction snapshot.\n";
} catch (Throwable $error) {
    foreach ([$first, $second] as $connection) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

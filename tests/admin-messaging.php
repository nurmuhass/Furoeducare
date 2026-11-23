<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Messaging\MessageContext;
use Furoeducare\Modules\Messaging\MessageRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';

$config = Config::load(dirname(__DIR__));
expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
    && preg_match('/\Afuroeducare_test_admin_[a-z0-9_]+\z/D', $config->databaseName) === 1,
    'Use an existing migrated isolated loopback administration test schema.');
$db = Database::connect($config);
$database = static fn (): PDO => $db;
$messages = new MessageRepository($database, new MessageContext($database), new RateLimiter($database));
$suffix = bin2hex(random_bytes(6));
$ids = [];
foreach (['owner' => 'teacher', 'assigned' => 'admin', 'triage' => 'admin', 'library' => 'admin'] as $name => $role) {
    $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
        ->execute(['message-rbac-' . $name . '-' . $suffix . '@example.test', '!synthetic-no-login']);
    $ids[$name] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$ids[$name], $role]);
}
grantTestStaffRole($db, $ids['assigned'], 'recruitment_officer');
grantTestStaffRole($db, $ids['triage'], 'recruitment_officer');
grantTestStaffRole($db, $ids['library'], 'library_manager');
$thread = $messages->create($ids['owner'], false, $ids['owner'], Role::Teacher,
    'Permission routing fixture ' . $suffix, 'Initial support request.', 'support', null, bin2hex(random_bytes(16)));
$revision = static function () use ($db, $thread): int {
    $query = $db->prepare('SELECT revision FROM message_conversations WHERE id = ?');
    $query->execute([$thread]);
    return (int) $query->fetchColumn();
};
$messages->manage($ids['assigned'], $thread, $revision(), 'assign', '');
$counts = static function () use ($db, $ids): array {
    $counts = [];
    foreach (['assigned', 'triage', 'library'] as $name) {
        $query = $db->prepare("SELECT COUNT(*) FROM verification_notifications WHERE user_id = ? AND event = 'messaging.new_message'");
        $query->execute([$ids[$name]]);
        $counts[$name] = (int) $query->fetchColumn();
    }
    return $counts;
};
$reply = static fn (string $body) => $messages->send($ids['owner'], false, $thread, $body, bin2hex(random_bytes(16)));
$before = $counts();
$reply('Reply while assignee remains qualified.');
$after = $counts();
expect($after['assigned'] === $before['assigned'] + 1 && $after['triage'] === $before['triage'] && $after['library'] === $before['library'],
    'A qualified assignee alone receives the owner reply.');

$db->prepare('DELETE FROM staff_user_roles WHERE user_id = ?')->execute([$ids['assigned']]);
grantTestStaffRole($db, $ids['assigned'], 'library_manager');
$before = $counts();
$reply('Reply after assignee moves to library management.');
$after = $counts();
expect($after['assigned'] === $before['assigned'] && $after['library'] === $before['library'] && $after['triage'] === $before['triage'] + 1,
    'Demoted assignees and library staff receive no new message alerts; qualified triage receives them.');

// A stale assignment must also fail closed after removal of the coarse administrator role.
grantTestStaffRole($db, $ids['assigned'], 'recruitment_officer');
$db->prepare('DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?')->execute([$ids['assigned'], 'admin']);
$before = $counts();
$reply('Reply after coarse administrator role removal.');
$after = $counts();
expect($after['assigned'] === $before['assigned'] && $after['library'] === $before['library'] && $after['triage'] === $before['triage'] + 1,
    'A stale fine-grained grant cannot notify an account without its coarse administrator role.');
$query = $db->prepare('SELECT assigned_admin_id FROM message_conversations WHERE id = ?');
$query->execute([$thread]);
expect((int) $query->fetchColumn() === $ids['assigned'], 'Notification fallback preserves assignment history.');
echo "PASS assigned message alerts recheck current permissions and fall back to qualified staff after demotion.\n";

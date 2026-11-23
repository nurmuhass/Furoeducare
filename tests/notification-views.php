<?php

declare(strict_types=1);

use Furoeducare\Core\View;

require dirname(__DIR__) . '/vendor/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$root = dirname(__DIR__);
$view = new View($root . '/resources/views/layout.php', 'Furoeducare');
$categories = ['account' => 'Account updates', 'recruitment' => 'Recruitment', 'messages' => 'Messages',
    'tutoring' => 'Tutoring', 'placements' => 'Placements', 'training' => 'Training'];
$common = ['signedIn' => true, 'csrf' => 'test-token', 'notice' => '', 'categories' => $categories,
    'wat' => static fn (string $value): string => $value];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$render = static fn (string $template, array $data): string => $view->render(
    $root . '/app/Modules/Notification/Views/' . $template . '.php', 'Notifications', $data + $common,
);
$feed = ['unread' => 1, 'category' => '', 'unreadOnly' => false, 'before' => PHP_INT_MAX];
$html = $render('feed', ['rows' => []] + $feed);
$assert(str_contains($html, 'No notifications to show'), 'Empty inbox is readable.');
$assert(!str_contains($html, 'name="through"'), 'Empty inbox cannot submit a mark-all snapshot.');
$assert(str_contains($html, 'Notifications, 0 unread'), 'Shared bell safely defaults to zero without provider.');
$rows = [];
for ($id = 30; $id >= 10; --$id) {
    $rows[] = ['id' => $id, 'category' => 'messages', 'title' => '<script>unsafe</script>',
        'message' => '<img src=x onerror=alert(1)>', 'path' => 'https://untrusted.example',
        'created_at' => '2026-11-23 10:00:00', 'read_at' => $id === 29 ? '2026-11-23 11:00:00' : null];
}
$html = $render('feed', ['rows' => $rows, 'notificationUnread' => 101] + $feed);
$assert(!str_contains($html, '<script>unsafe</script>') && !str_contains($html, '<img src=x'), 'Feed escapes stored presentation fields.');
$assert(!str_contains($html, 'https://untrusted.example'), 'Feed never prints stored destinations.');
$assert(str_contains($html, '/notifications/open?id=30'), 'Destinations use the owner-checked open endpoint.');
$assert(str_contains($html, 'name="through" value="30"') && str_contains($html, 'before=11'), 'Mark-all snapshot and pagination use visible page IDs.');
$assert(!str_contains($html, '/notifications/open?id=10'), 'Extra cursor sentinel is not rendered.');
$assert(str_contains($html, 'Notifications, 101 unread') && str_contains($html, '>99+</span>'), 'Bell caps its visible count while preserving the accessible total.');
$assert(substr_count($html, 'name="action" value="read"') === 19, 'Only unread visible rows have explicit POST read actions.');
$html = $render('feed', ['rows' => $rows, 'category' => 'messages', 'unreadOnly' => true, 'before' => 31, 'unread' => 20]);
$assert(str_contains($html, 'Mark these and earlier notifications as read')
    && str_contains($html, 'across all categories'), 'Filtered and older pages explain the global bounded read action.');
$enabled = array_fill_keys(array_keys($categories), true);
$disabled = array_fill_keys(array_keys($categories), false);
$html = $render('preferences', ['preferences' => ['in_app' => $enabled, 'email' => $disabled]]);
$assert(str_contains($html, 'name="channels" value="independent"'), 'Settings form identifies independent channels.');
$assert(preg_match('/name="in_app\[\]" value="account" checked/', $html) === 1
    && preg_match('/name="email\[\]" value="account" checked/', $html) === 0, 'Settings render channel choices independently.');
restore_error_handler();
echo "PASS notification empty, populated, escaped, paginated, read-action, bell and independent settings views.\n";

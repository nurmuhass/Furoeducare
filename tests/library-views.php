<?php

declare(strict_types=1);

use Furoeducare\Core\View;

require dirname(__DIR__) . '/vendor/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
$view = new View($root . '/resources/views/layout.php', 'Furoeducare');
$common = ['signedIn' => true, 'csrf' => 'synthetic-token', 'notice' => '', 'values' => null, 'error' => '',
    'wat' => static fn (string $value): string => $value];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$render = static fn (string $template, array $data): string => $view->render(
    $root . '/app/Modules/Library/Views/' . $template . '.php', 'Library', $data + $common,
);
$resource = ['id' => 1, 'reference' => 'LB-' . str_repeat('A', 32), 'revision' => 2,
    'status' => 'removed', 'downloads' => 3, 'material_extension' => null];
$html = $render('manage', ['resource' => $resource]);
$assert(str_contains($html, 'Resource removed') && str_contains($html, '3 recorded downloads'), 'Removed staff view renders a readable tombstone.');
$assert(!str_contains($html, 'type="file"'), 'Removed staff view does not offer ineffective editing controls.');
$resource += ['title' => '<script>unsafe</script>', 'author' => '', 'access' => 'restricted',
    'material_bytes' => 100, 'grants' => [], 'tags' => [], 'rights_confirmed_at' => '2026-01-01 00:00:00'];
$resource['status'] = 'published';
$resource['material_extension'] = 'pdf';
$resource['grants_next'] = 12;
$html = $render('manage', ['resource' => $resource]);
$assert(str_contains($html, 'grants_before=12') && str_contains($html, 'Older grants'), 'Staff can navigate older access grants.');
$assert(preg_match('/id="lib-confirmed"[^>]*checked/', $html) !== 1, 'Rights confirmation must be explicit on each edit.');
$assert(!str_contains($html, '<script>unsafe</script>'), 'Staff metadata is escaped.');
$resource['is_available'] = false;
$html = $render('catalogue', ['rows' => [$resource], 'staff' => false, 'saved' => true,
    'root' => '/digital-library/saved', 'next' => null, 'filters' => ['q' => '', 'category' => '', 'subject' => '', 'level' => '']]);
$assert(str_contains($html, 'Currently unavailable') && str_contains($html, 'Remove saved resource'), 'Unavailable saved items remain removable.');
$assert(!str_contains($html, '/digital-library/resource?ref='), 'Unavailable saved items do not offer broken public links.');
$resource['access'] = 'future_paid';
$resource['description'] = '';
$html = $render('resource', ['resource' => $resource, 'canDownload' => true]);
$assert(!str_contains($html, '/digital-library/download?ref='), 'Future paid view cannot enable a download even with a mistaken presentation hint.');
restore_error_handler();
echo "PASS library tombstones, grant pagination, rights confirmation, escaping and unavailable controls.\n";

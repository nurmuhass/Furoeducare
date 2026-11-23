<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Admin\AdminManagement;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 3 || $argv[1] !== 'bootstrap') {
    fwrite(STDERR, "Usage: php bin/staff.php bootstrap verified-account@example.com\n");
    exit(2);
}
try {
    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';
    $db = Database::connect(Config::load($root));
    $id = (new AdminManagement(static fn (): PDO => $db))->bootstrap($argv[2]);
    echo 'Bootstrapped Super Admin account #' . $id . ". Sign in again.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof DomainException ? $error->getMessage() . PHP_EOL : "Staff bootstrap failed. Check migrations and private configuration.\n");
    exit(1);
}

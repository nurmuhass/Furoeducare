<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Library\LibraryFiles;
use Furoeducare\Modules\Library\LibraryRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';
    $config = Config::load($root);
    $db = Database::connect($config);
    $files = new LibraryFiles($config->libraryStorageDirectory, $root . '/public');
    $library = new LibraryRepository(static fn (): PDO => $db, $files);
    echo 'Removed queued files: ' . $library->cleanup(500) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Library cleanup failed (' . $error::class . "). Check private storage and database configuration.\n");
    exit(1);
}

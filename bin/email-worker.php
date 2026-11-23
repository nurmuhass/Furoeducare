<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Email\EmailWorker;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$limit = filter_var($argv[1] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
if ($argc > 2 || $limit === false) {
    fwrite(STDERR, "Usage: php bin/email-worker.php [1-1000]\n");
    exit(2);
}
try {
    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';
    $config = Config::load($root);
    $db = Database::connect($config);
    $worker = new EmailWorker(static fn (): PDO => $db, $config);
    $counts = $worker->run($limit);
    echo json_encode($counts, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($counts['failed'] > 0 || $counts['uncertain'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Email worker failed (' . $error::class . "). Check configuration, migrations and the private application log.\n");
    exit(1);
}

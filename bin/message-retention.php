<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Messaging\MessageContext;
use Furoeducare\Modules\Messaging\MessageRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 1) {
    fwrite(STDERR, "Usage: php bin/message-retention.php\n");
    exit(2);
}
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $db = Database::connect(Config::load(dirname(__DIR__)));
    $database = static fn (): PDO => $db;
    $messages = new MessageRepository($database, new MessageContext($database), new RateLimiter($database));
    printf("Purged %d expired conversations in this batch.\n", $messages->purge());
} catch (Throwable $error) {
    fwrite(STDERR, 'Message retention failed (' . $error::class . "). Check database configuration and migrations.\n");
    exit(1);
}

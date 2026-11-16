<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$command = $argv[1] ?? '';
if ($argc !== 2 || !in_array($command, ['status', 'migrate', 'rollback', 'seed'], true)) {
    fwrite(STDERR, "Usage: php bin/database.php status|migrate|rollback|seed\n");
    exit(2);
}

$root = dirname(__DIR__);
try {
    require $root . '/vendor/autoload.php';
    $config = Config::load($root);
    $connection = Database::connect($config);
    if (!str_starts_with((string) $connection->query('SELECT VERSION()')->fetchColumn(), '8.4.')) {
        throw new RuntimeException('These schema commands require MySQL 8.4 LTS.');
    }

    printf("Database %s on %s:%d\n", $config->databaseName, $config->databaseHost, $config->databasePort);
    $migrations = new MigrationRunner($connection, $root . '/database/migrations');
    switch ($command) {
        case 'status':
            foreach ($migrations->status() as $version => $state) {
                printf("%-38s %s\n", $version, $state);
            }
            break;
        case 'migrate':
            $applied = $migrations->migrate();
            echo $applied === [] ? "No pending migrations.\n" : 'Applied ' . implode(', ', $applied) . ".\n";
            break;
        case 'rollback':
            $version = $migrations->rollback();
            echo $version === null ? "No applied migrations.\n" : "Rolled back $version.\n";
            break;
        case 'seed':
            $seeds = new SeedRunner($connection, $root . '/database/seeders');
            echo 'Ran ' . implode(', ', $migrations->withCurrentSchema($seeds->run(...))) . ".\n";
            break;
    }
} catch (PDOException $error) {
    fwrite(STDERR, "Database error (SQLSTATE {$error->getCode()}). Verify the configured server, database and privileges.\n");
    exit(1);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Database command failed (' . $error::class . ").\n");
    exit(1);
}

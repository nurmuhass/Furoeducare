<?php

declare(strict_types=1);

namespace Furoeducare\Core\Database;

use Furoeducare\Core\Database;
use PDO;
use RuntimeException;

final class SeedRunner
{
    public function __construct(private readonly PDO $connection, private readonly string $directory)
    {
    }

    /** @return list<string> */
    public function run(): array
    {
        $files = is_dir($this->directory) ? glob($this->directory . '/*.php') : false;
        if ($files === false || $files === []) {
            throw new RuntimeException('No database seed files were found.');
        }

        return Database::transaction($this->connection, static function (PDO $connection) use ($files): array {
            $executed = [];
            foreach ($files as $file) {
                $seed = require $file;
                if (!$seed instanceof \Closure) {
                    throw new RuntimeException('Invalid seed file ' . basename($file) . '.');
                }
                $seed($connection);
                $executed[] = basename($file, '.php');
            }
            return $executed;
        });
    }
}

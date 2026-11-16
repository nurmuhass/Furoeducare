<?php

declare(strict_types=1);

namespace Furoeducare\Core;

use LogicException;
use PDO;
use Pdo\Mysql;
use Throwable;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->databaseHost,
            $config->databasePort,
            $config->databaseName
        );

        $connection = new PDO($dsn, $config->databaseUser, $config->databasePassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            Mysql::ATTR_MULTI_STATEMENTS => false,
        ]);
        $connection->exec("SET time_zone = '+00:00'");

        return $connection;
    }

    /** Wrap transactional DML. The callback must not manage the transaction or run DDL. */
    public static function transaction(PDO $connection, callable $operation): mixed
    {
        if ($connection->inTransaction()) {
            throw new LogicException('A database transaction is already active.');
        }

        $connection->beginTransaction();
        try {
            $result = $operation($connection);
            $connection->commit();
            return $result;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
}

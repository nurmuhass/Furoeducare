<?php

declare(strict_types=1);

namespace Furoeducare\Core\Database;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(private readonly PDO $connection, private readonly string $directory)
    {
    }

    /** @return array<string, string> */
    public function status(): array
    {
        return $this->locked(function (): array {
            $files = $this->files();
            $history = $this->history();
            $status = [];
            foreach ($files as $version => $file) {
                $row = $history[$version] ?? null;
                $status[$version] = match (true) {
                    $row === null => 'pending',
                    $row['state'] !== 'applied' => $row['state'],
                    $row['checksum'] !== $file['checksum'] => 'modified',
                    default => 'applied',
                };
            }
            foreach (array_diff_key($history, $files) as $version => $row) {
                $status[$version] = 'missing file (' . $row['state'] . ')';
            }
            return $status;
        });
    }

    /** @return list<string> */
    public function migrate(): array
    {
        return $this->locked(function (): array {
            $files = $this->files();
            $this->createLedger();
            $history = $this->history();
            $this->assertCleanHistory($files, $history);

            $applied = [];
            foreach (array_diff_key($files, $history) as $version => $file) {
                $migration = $this->load($version, $file['path']);
                $this->connection->prepare(
                    "INSERT INTO schema_migrations (version, checksum, state) VALUES (?, ?, 'applying')"
                )->execute([$version, $file['checksum']]);

                $this->executeDdl($version, $migration['up']);
                $this->connection->prepare(
                    "UPDATE schema_migrations SET state = 'applied', applied_at = CURRENT_TIMESTAMP(6) WHERE version = ?"
                )->execute([$version]);
                $applied[] = $version;
            }
            return $applied;
        });
    }

    public function rollback(): ?string
    {
        return $this->locked(function (): ?string {
            $files = $this->files();
            $history = $this->history();
            $this->assertCleanHistory($files, $history);

            $version = array_key_last($history);
            if ($version === null) {
                return null;
            }
            $migration = $this->load($version, $files[$version]['path']);
            if ($migration['down'] === null) {
                throw new RuntimeException("Migration $version has no rollback.");
            }

            $this->connection->prepare(
                "UPDATE schema_migrations SET state = 'rolling_back' WHERE version = ?"
            )->execute([$version]);
            $this->executeDdl($version, $migration['down']);
            $this->connection->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$version]);
            return $version;
        });
    }

    public function withCurrentSchema(callable $operation): mixed
    {
        return $this->locked(function () use ($operation): mixed {
            $files = $this->files();
            $history = $this->history();
            $this->assertCleanHistory($files, $history);
            if (array_diff_key($files, $history) !== []) {
                throw new RuntimeException('Migrations are pending. Run migrate before seeding.');
            }
            return $operation();
        });
    }

    private function files(): array
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('The migrations directory is missing.');
        }

        $paths = glob($this->directory . '/*.php');
        if ($paths === false || $paths === []) {
            throw new RuntimeException('No migration files were found.');
        }

        $files = [];
        $previousSequence = null;
        foreach ($paths as $path) {
            $version = basename($path, '.php');
            $sequence = substr($version, 0, 4);
            if (preg_match('/\A[0-9]{4}_[a-z][a-z0-9_]{0,58}\z/', $version) !== 1 || $previousSequence === $sequence) {
                throw new RuntimeException("Invalid or duplicate migration sequence in $version.");
            }

            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException("Cannot read migration $version.");
            }
            $files[$version] = [
                'path' => $path,
                'checksum' => hash('sha256', str_replace("\r\n", "\n", $source)),
            ];
            $previousSequence = $sequence;
        }

        return $files;
    }

    private function load(string $version, string $path): array
    {
        $migration = require $path;
        if (!is_array($migration) || !is_string($migration['up'] ?? null) || trim($migration['up']) === '') {
            throw new RuntimeException("Migration $version needs a non-empty up statement.");
        }

        $down = $migration['down'] ?? null;
        if ($down !== null && (!is_string($down) || trim($down) === '')) {
            throw new RuntimeException("Migration $version has an invalid down statement.");
        }
        return ['up' => $migration['up'], 'down' => $down];
    }

    private function createLedger(): void
    {
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                state ENUM('applying', 'applied', 'rolling_back') NOT NULL,
                started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                applied_at DATETIME(6) NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    private function history(): array
    {
        $exists = $this->connection->query(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
            SQL)->fetchColumn();
        if ((int) $exists === 0) {
            return [];
        }

        $rows = $this->connection->query(
            'SELECT version, checksum, state FROM schema_migrations ORDER BY version'
        )->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        return $rows;
    }

    private function assertCleanHistory(array $files, array $history): void
    {
        foreach ($history as $version => $row) {
            if ($row['state'] !== 'applied') {
                throw new RuntimeException("Migration $version is {$row['state']}. Inspect the schema before continuing.");
            }
            if (!isset($files[$version])) {
                throw new RuntimeException("Applied migration $version has no matching file.");
            }
            if ($row['checksum'] !== $files[$version]['checksum']) {
                throw new RuntimeException("Applied migration $version has been modified.");
            }
        }

        // Applied migrations must be a contiguous prefix of the versioned files.
        if (array_keys($history) !== array_slice(array_keys($files), 0, count($history))) {
            throw new RuntimeException('Migration history is out of order. Add new migrations after the latest applied version.');
        }
    }

    private function executeDdl(string $version, string $sql): void
    {
        try {
            $this->connection->exec($sql);
        } catch (PDOException $error) {
            throw new RuntimeException(
                "DDL for migration $version failed (SQLSTATE {$error->getCode()}). Run status and inspect the schema.",
                0,
                $error
            );
        }
    }

    private function locked(callable $operation): mixed
    {
        if ($this->connection->inTransaction()
            || (int) $this->connection->query('SELECT @@session.autocommit')->fetchColumn() !== 1) {
            throw new RuntimeException('Schema commands require an autocommit connection outside a transaction.');
        }

        $schema = $this->connection->query('SELECT LOWER(DATABASE())')->fetchColumn();
        if (!is_string($schema) || $schema === '') {
            throw new RuntimeException('Select a database before running schema commands.');
        }

        $name = 'furoeducare:' . substr(hash('sha256', $schema), 0, 48);
        $acquire = $this->connection->prepare('SELECT GET_LOCK(?, 10)');
        $acquire->execute([$name]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new RuntimeException('The database schema lock is unavailable. Try again after the other schema command finishes.');
        }

        try {
            return $operation();
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try {
                $this->connection->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
            } catch (PDOException $error) {
                if (!isset($failure)) {
                    throw $error;
                }
            }
        }
    }
}

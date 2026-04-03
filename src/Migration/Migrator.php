<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Migration;

use PHPdot\Database\Connection;
use PHPdot\Database\Exception\MigrationException;
use PHPdot\Database\Schema\SchemaBuilder;
use Throwable;

/**
 * Runs and rolls back database migrations.
 *
 * Scans a directory for migration files, compares with the repository
 * to find pending migrations, and executes them in order.
 */
final class Migrator
{
    /**
     * @param Connection $connection The database connection
     * @param MigrationRepository $repository The migration state repository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationRepository $repository,
    ) {}

    /**
     * Run all pending migrations.
     *
     * @param string $path The directory containing migration files
     * @throws MigrationException When a migration fails
     * @return list<string> The names of the migrations that were run
     */
    public function run(string $path): array
    {
        $this->ensureRepository();

        $files = $this->getMigrationFiles($path);
        $ran = $this->repository->getRan();
        $pending = array_diff($files, $ran);

        if ($pending === []) {
            return [];
        }

        $batch = $this->repository->getNextBatchNumber();
        $schema = $this->connection->schema();
        $executed = [];

        foreach ($pending as $migration) {
            $this->runMigration($path, $migration, $schema, 'up');
            $this->repository->log($migration, $batch);
            $executed[] = $migration;
        }

        return $executed;
    }

    /**
     * Roll back the last batch of migrations.
     *
     * @param string $path The directory containing migration files
     * @throws MigrationException When a rollback fails
     * @return list<string> The names of the migrations that were rolled back
     */
    public function rollback(string $path): array
    {
        $this->ensureRepository();

        $migrations = $this->repository->getLast();

        if ($migrations === []) {
            return [];
        }

        $schema = $this->connection->schema();
        $rolledBack = [];

        foreach ($migrations as $migration) {
            try {
                $this->runMigration($path, $migration, $schema, 'down');
            } catch (Throwable $e) {
                throw MigrationException::rollbackFailed($migration, $e->getMessage());
            }

            $this->repository->delete($migration);
            $rolledBack[] = $migration;
        }

        return $rolledBack;
    }

    /**
     * Roll back all migrations.
     *
     * @param string $path The directory containing migration files
     * @throws MigrationException When a rollback fails
     * @return list<string> The names of the migrations that were rolled back
     */
    public function reset(string $path): array
    {
        $this->ensureRepository();

        $ran = $this->repository->getRan();
        $reversed = array_reverse($ran);
        $schema = $this->connection->schema();
        $rolledBack = [];

        foreach ($reversed as $migration) {
            try {
                $this->runMigration($path, $migration, $schema, 'down');
            } catch (Throwable $e) {
                throw MigrationException::rollbackFailed($migration, $e->getMessage());
            }

            $this->repository->delete($migration);
            $rolledBack[] = $migration;
        }

        return $rolledBack;
    }

    /**
     * Get the list of pending migration names.
     *
     * @param string $path The directory containing migration files
     * @return list<string>
     */
    public function getPending(string $path): array
    {
        $this->ensureRepository();

        $files = $this->getMigrationFiles($path);
        $ran = $this->repository->getRan();

        return array_values(array_diff($files, $ran));
    }

    /**
     * Dry-run pending migrations, capturing SQL without executing permanently.
     *
     * For each pending migration, begins a transaction, runs up(), captures
     * the query log, then rolls back so no changes persist.
     *
     * @param string $path The directory containing migration files
     * @return list<array{migration: string, queries: list<string>}>
     */
    public function pretend(string $path): array
    {
        $this->ensureRepository();

        $pending = $this->getPending($path);
        $result = [];

        foreach ($pending as $migration) {
            $this->connection->enableQueryLog();
            $this->connection->beginTransaction();

            try {
                $filePath = rtrim($path, '/') . '/' . $migration . '.php';

                if (file_exists($filePath)) {
                    /** @var Migration|mixed $instance */
                    $instance = require $filePath;

                    if ($instance instanceof Migration) {
                        $instance->up($this->connection->schema());
                    }
                }
            } catch (Throwable) {
                // Schema changes may fail in pretend if table already exists, etc.
            }

            $this->connection->rollBack();

            $queries = array_map(
                static fn(array $entry): string => $entry['query'],
                $this->connection->getQueryLog(),
            );
            $this->connection->flushQueryLog();
            $this->connection->disableQueryLog();

            $result[] = ['migration' => $migration, 'queries' => $queries];
        }

        return $result;
    }

    /**
     * Reset all migrations and re-run them from scratch.
     *
     * @param string $path The directory containing migration files
     * @throws MigrationException When a migration fails
     * @return list<string> The names of the migrations that were run
     */
    public function refresh(string $path): array
    {
        $this->reset($path);

        return $this->run($path);
    }

    /**
     * Get the status of all migrations (ran or pending).
     *
     * @param string $path The directory containing migration files
     * @return list<array{migration: string, status: string, batch: int|null}>
     */
    public function status(string $path): array
    {
        $this->ensureRepository();

        $ran = $this->repository->getRan();
        $files = $this->getMigrationFiles($path);
        $result = [];

        foreach ($files as $name) {
            $result[] = [
                'migration' => $name,
                'status' => in_array($name, $ran, true) ? 'ran' : 'pending',
                'batch' => $this->repository->getBatch($name),
            ];
        }

        return $result;
    }

    // ---------------------------------------------------------------
    //  Internal
    // ---------------------------------------------------------------

    /**
     * Ensure the migrations repository exists.
     */
    private function ensureRepository(): void
    {
        if (!$this->repository->repositoryExists()) {
            $this->repository->createRepository();
        }
    }

    /**
     * Get sorted migration file names from a directory.
     *
     * @param string $path The directory path
     * @return list<string>
     */
    private function getMigrationFiles(string $path): array
    {
        $realPath = realpath($path);

        if ($realPath === false || !is_dir($realPath)) {
            return [];
        }

        $files = glob($realPath . '/*.php');

        if ($files === false) {
            return [];
        }

        sort($files);

        $names = [];
        foreach ($files as $file) {
            $names[] = pathinfo($file, PATHINFO_FILENAME);
        }

        return $names;
    }

    /**
     * Run a single migration's up or down method.
     *
     * @param string $path The directory containing migration files
     * @param string $migration The migration file name (without extension)
     * @param SchemaBuilder $schema The schema builder
     * @param string $direction Either 'up' or 'down'
     * @throws MigrationException When the migration fails
     */
    private function runMigration(string $path, string $migration, SchemaBuilder $schema, string $direction): void
    {
        $filePath = rtrim($path, '/') . '/' . $migration . '.php';

        if (!file_exists($filePath)) {
            throw MigrationException::migrationFailed($migration, 'Migration file not found: ' . $filePath);
        }

        /** @var Migration|mixed $instance */
        $instance = require $filePath;

        if (!$instance instanceof Migration) {
            throw MigrationException::migrationFailed($migration, 'Migration file must return a Migration instance');
        }

        try {
            if ($direction === 'up') {
                $instance->up($schema);
            } else {
                $instance->down($schema);
            }
        } catch (MigrationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw MigrationException::migrationFailed($migration, $e->getMessage());
        }
    }
}

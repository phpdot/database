<?php

declare(strict_types=1);

/**
 * Runs and rolls back database migrations.
 *
 * Scans a directory for migration files, compares with the repository
 * to find pending migrations, and executes them in order.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Migration;

use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\Exception\MigrationException;
use PHPdot\Database\Schema\SchemaBuilder;
use Throwable;

final class Migrator
{
    /**
     * Wire the migrator to its connection and migration repository.
     *
     * @param DatabaseConnection $connection The database connection
     * @param MigrationRepository $repository The migration state repository
     */
    public function __construct(
        private readonly DatabaseConnection $connection,
        private readonly MigrationRepository $repository,
    ) {}

    /**
     * Run all pending migrations.
     *
     * @param string $path The directory containing migration files
     *
     * @throws MigrationException When a migration fails
     *
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
     *
     * @throws MigrationException When a rollback fails
     *
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
     *
     * @throws MigrationException When a rollback fails
     *
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
     *
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
     * Compute pending migrations without creating the repository table. Used by
     * the dry-run pretend() so it has no side effects; a missing repository is
     * treated as "nothing has run yet".
     *
     * @param string $path The directory containing migration files
     *
     * @return list<string>
     */
    private function pendingWithoutRepository(string $path): array
    {
        $files = $this->getMigrationFiles($path);
        $ran = $this->repository->repositoryExists() ? $this->repository->getRan() : [];

        return array_values(array_diff($files, $ran));
    }

    /**
     * Dry-run pending migrations, capturing the SQL each would run without
     * executing anything or touching the database.
     *
     * The migrations repository table is not created and no queries are sent;
     * each migration's up() runs against the connection's capture mode, which
     * records the SQL instead of executing it. When the repository table does
     * not yet exist, every migration file is treated as pending.
     *
     * @param string $path The directory containing migration files
     *
     * @return list<array{migration: string, queries: list<string>}>
     */
    public function pretend(string $path): array
    {
        $pending = $this->pendingWithoutRepository($path);
        $result = [];

        foreach ($pending as $migration) {
            $queries = $this->connection->pretend(function () use ($path, $migration): void {
                $filePath = rtrim($path, '/') . '/' . $migration . '.php';

                if (file_exists($filePath)) {
                    /**
                     * @var Migration|mixed $instance
                     */
                    $instance = require $filePath;

                    if ($instance instanceof Migration) {
                        $instance->up($this->connection->schema());
                    }
                }
            });

            $result[] = ['migration' => $migration, 'queries' => $queries];
        }

        return $result;
    }

    /**
     * Reset all migrations and re-run them from scratch.
     *
     * @param string $path The directory containing migration files
     *
     * @throws MigrationException When a migration fails
     *
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
     *
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

    /**
     * Ensure the migrations repository exists.
     *
     * @return void
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
     *
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
     *
     * @throws MigrationException When the migration fails
     *
     * @return void
     */
    private function runMigration(string $path, string $migration, SchemaBuilder $schema, string $direction): void
    {
        $filePath = rtrim($path, '/') . '/' . $migration . '.php';

        if (!file_exists($filePath)) {
            throw MigrationException::migrationFailed($migration, 'Migration file not found: ' . $filePath);
        }

        /**
         * @var Migration|mixed $instance
         */
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

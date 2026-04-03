<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Migration;

use PHPdot\Database\Connection;
use PHPdot\Database\Exception\MigrationException;

/**
 * Tracks migration state in a `migrations` database table.
 *
 * Records which migrations have been run and their batch number
 * so that rollbacks can be performed in the correct order.
 */
final class MigrationRepository
{
    private readonly string $table;

    /**
     * @param Connection $connection The database connection
     * @param string $table The migrations table name
     */
    public function __construct(
        private readonly Connection $connection,
        string $table = 'migrations',
    ) {
        $this->table = $connection->getTablePrefix() . $table;
    }

    /**
     * Create the migrations table if it does not exist.
     *
     * @throws MigrationException When the table cannot be created
     */
    public function createRepository(): void
    {
        $driver = $this->connection->getDriverName();

        $sql = match ($driver) {
            'sqlite' => 'CREATE TABLE IF NOT EXISTS "' . $this->table . '" ('
                . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                . '"migration" VARCHAR(255) NOT NULL, '
                . '"batch" INTEGER NOT NULL'
                . ')',
            'pgsql' => 'CREATE TABLE IF NOT EXISTS "' . $this->table . '" ('
                . '"id" SERIAL PRIMARY KEY, '
                . '"migration" VARCHAR(255) NOT NULL, '
                . '"batch" INTEGER NOT NULL'
                . ')',
            default => 'CREATE TABLE IF NOT EXISTS `' . $this->table . '` ('
                . '`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
                . '`migration` VARCHAR(255) NOT NULL, '
                . '`batch` INT NOT NULL'
                . ')',
        };

        try {
            $this->connection->statement($sql);
        } catch (\Throwable) {
            throw MigrationException::tableNotCreated();
        }
    }

    /**
     * Check if the migrations repository table exists.
     */
    public function repositoryExists(): bool
    {
        $driver = $this->connection->getDriverName();

        $result = match ($driver) {
            'sqlite' => $this->connection->selectOne(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                [$this->table],
            ),
            'pgsql' => $this->connection->selectOne(
                'SELECT tablename FROM pg_tables WHERE schemaname = \'public\' AND tablename = ?',
                [$this->table],
            ),
            default => $this->connection->selectOne(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$this->connection->getDatabaseName(), $this->table],
            ),
        };

        return $result !== null;
    }

    /**
     * Get the list of already-run migration names.
     *
     * @return list<string>
     */
    public function getRan(): array
    {
        $quote = $this->getQuoteChar();
        $results = $this->connection->select(
            'SELECT ' . $quote . 'migration' . $quote . ' FROM ' . $quote . $this->table . $quote . ' ORDER BY ' . $quote . 'batch' . $quote . ' ASC, ' . $quote . 'migration' . $quote . ' ASC',
        );

        $ran = [];
        foreach ($results->all() as $row) {
            /** @var string $migration */
            $migration = $row['migration'] ?? '';
            $ran[] = $migration;
        }

        return $ran;
    }

    /**
     * Get the last batch of migrations.
     *
     * @return list<string>
     */
    public function getLast(): array
    {
        $quote = $this->getQuoteChar();
        $lastBatch = $this->getLastBatchNumber();

        if ($lastBatch === 0) {
            return [];
        }

        $results = $this->connection->select(
            'SELECT ' . $quote . 'migration' . $quote . ' FROM ' . $quote . $this->table . $quote
            . ' WHERE ' . $quote . 'batch' . $quote . ' = ? ORDER BY ' . $quote . 'migration' . $quote . ' DESC',
            [$lastBatch],
        );

        $migrations = [];
        foreach ($results->all() as $row) {
            /** @var string $migration */
            $migration = $row['migration'] ?? '';
            $migrations[] = $migration;
        }

        return $migrations;
    }

    /**
     * Log that a migration was run.
     *
     * @param string $migration The migration name
     * @param int $batch The batch number
     */
    public function log(string $migration, int $batch): void
    {
        $quote = $this->getQuoteChar();
        $this->connection->insert(
            'INSERT INTO ' . $quote . $this->table . $quote
            . ' (' . $quote . 'migration' . $quote . ', ' . $quote . 'batch' . $quote . ') VALUES (?, ?)',
            [$migration, $batch],
        );
    }

    /**
     * Remove a migration record.
     *
     * @param string $migration The migration name
     */
    public function delete(string $migration): void
    {
        $quote = $this->getQuoteChar();
        $this->connection->delete(
            'DELETE FROM ' . $quote . $this->table . $quote . ' WHERE ' . $quote . 'migration' . $quote . ' = ?',
            [$migration],
        );
    }

    /**
     * Get the next batch number.
     */
    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    /**
     * Get the last batch number.
     */
    public function getLastBatchNumber(): int
    {
        $quote = $this->getQuoteChar();
        $result = $this->connection->selectOne(
            'SELECT MAX(' . $quote . 'batch' . $quote . ') AS ' . $quote . 'batch' . $quote
            . ' FROM ' . $quote . $this->table . $quote,
        );

        if ($result === null) {
            return 0;
        }

        $batch = $result['batch'] ?? null;

        if ($batch === null) {
            return 0;
        }

        if (is_int($batch)) {
            return $batch;
        }

        if (is_string($batch) || is_float($batch)) {
            return (int) $batch;
        }

        return 0;
    }

    /**
     * Get the batch number for a specific migration.
     *
     * @param string $migration The migration name
     * @return int|null The batch number, or null if the migration has not been run
     */
    public function getBatch(string $migration): ?int
    {
        $quote = $this->getQuoteChar();
        $result = $this->connection->selectOne(
            'SELECT ' . $quote . 'batch' . $quote . ' FROM ' . $quote . $this->table . $quote
            . ' WHERE ' . $quote . 'migration' . $quote . ' = ?',
            [$migration],
        );

        if ($result === null) {
            return null;
        }

        $batch = $result['batch'] ?? null;

        if ($batch === null) {
            return null;
        }

        if (is_int($batch)) {
            return $batch;
        }

        if (is_string($batch) || is_float($batch)) {
            return (int) $batch;
        }

        return null;
    }

    /**
     * Get the identifier quote character for the current driver.
     */
    private function getQuoteChar(): string
    {
        return match ($this->connection->getDriverName()) {
            'mysql' => '`',
            default => '"',
        };
    }
}

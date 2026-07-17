<?php

declare(strict_types=1);

/**
 * High-level schema builder for creating, altering, and dropping tables.
 *
 * Receives a DatabaseConnection and SchemaGrammar, and delegates DDL compilation
 * to the grammar while executing the resulting SQL through the connection.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema;

use Closure;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\Schema\Grammar\SchemaGrammar;

final class SchemaBuilder
{
    /**
     * Bind the schema builder to a connection and schema grammar.
     *
     * @param DatabaseConnection $connection The database connection
     * @param SchemaGrammar $grammar The schema grammar for DDL compilation
     */
    public function __construct(
        private readonly DatabaseConnection $connection,
        private readonly SchemaGrammar $grammar,
    ) {}

    /**
     * Create a new table with the given blueprint callback.
     *
     * @param string $table The table name
     * @param \Closure(Blueprint): void $callback Blueprint definition callback
     *
     * @return void
     */
    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $this->connection->statement($this->grammar->compileCreate($blueprint));

        foreach ($this->grammar->compileCreateIndexes($blueprint) as $indexSql) {
            $this->connection->statement($indexSql);
        }
    }

    /**
     * Modify an existing table with the given blueprint callback.
     *
     * @param string $table The table name
     * @param \Closure(Blueprint): void $callback Blueprint definition callback
     *
     * @return void
     */
    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $statements = $this->grammar->compileAlter($blueprint);

        foreach ($statements as $sql) {
            $this->connection->statement($sql);
        }
    }

    /**
     * Drop a table.
     *
     * @param string $table The table name
     *
     * @return void
     */
    public function drop(string $table): void
    {
        $sql = $this->grammar->compileDrop($table);
        $this->connection->statement($sql);
    }

    /**
     * Drop a table if it exists.
     *
     * @param string $table The table name
     *
     * @return void
     */
    public function dropIfExists(string $table): void
    {
        $sql = $this->grammar->compileDropIfExists($table);
        $this->connection->statement($sql);
    }

    /**
     * Rename a table.
     *
     * @param string $from The current table name
     * @param string $to The new table name
     *
     * @return void
     */
    public function rename(string $from, string $to): void
    {
        $sql = $this->grammar->compileRename($from, $to);
        $this->connection->statement($sql);
    }

    /**
     * Check if a table exists.
     *
     * @param string $table The table name
     *
     * @return bool
     */
    public function hasTable(string $table): bool
    {
        $prefixedTable = $this->connection->getTablePrefix() . $table;

        return match ($this->connection->getDriverName()) {
            'sqlite' => $this->connection->selectOne(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                [$prefixedTable],
            ) !== null,
            'pgsql' => $this->connection->selectOne(
                'SELECT tablename FROM pg_tables WHERE schemaname = \'public\' AND tablename = ?',
                [$prefixedTable],
            ) !== null,
            default => $this->connection->selectOne(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$this->connection->getDatabaseName(), $prefixedTable],
            ) !== null,
        };
    }

    /**
     * Check if a column exists in a table.
     *
     * @param string $table The table name
     * @param string $column The column name
     *
     * @return bool
     */
    public function hasColumn(string $table, string $column): bool
    {
        $columns = $this->getColumnListing($table);

        return in_array(strtolower($column), array_map('strtolower', $columns), true);
    }

    /**
     * Get the column listing for a table.
     *
     * @param string $table The table name
     *
     * @return list<string>
     */
    public function getColumnListing(string $table): array
    {
        $prefixedTable = $this->connection->getTablePrefix() . $table;

        $results = match ($this->connection->getDriverName()) {
            'sqlite' => $this->connection->select(
                'PRAGMA table_xinfo(' . $this->grammar->wrapColumn($prefixedTable) . ')',
            ),
            'pgsql' => $this->connection->select(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = ?',
                [$prefixedTable],
            ),
            default => $this->connection->select(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$this->connection->getDatabaseName(), $prefixedTable],
            ),
        };

        $columns = [];
        foreach ($results->all() as $row) {
            /**
             * @var string $col
             */
            $col = match ($this->connection->getDriverName()) {
                'sqlite' => $row['name'] ?? '',
                'pgsql' => $row['column_name'] ?? '',
                default => $row['COLUMN_NAME'] ?? '',
            };
            $columns[] = $col;
        }

        return $columns;
    }

    /**
     * Get a list of all tables in the database.
     *
     * @return list<string>
     */
    public function getTables(): array
    {
        $results = match ($this->connection->getDriverName()) {
            'sqlite' => $this->connection->select(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            ),
            'pgsql' => $this->connection->select(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename",
            ),
            default => $this->connection->select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
                [$this->connection->getDatabaseName()],
            ),
        };

        $tables = [];
        foreach ($results->all() as $row) {
            /**
             * @var string $name
             */
            $name = match ($this->connection->getDriverName()) {
                'sqlite' => $row['name'] ?? '',
                'pgsql' => $row['tablename'] ?? '',
                default => $row['TABLE_NAME'] ?? '',
            };
            $tables[] = $name;
        }

        return $tables;
    }

    /**
     * Get the underlying database connection.
     *
     * @return DatabaseConnection
     */
    public function getConnection(): DatabaseConnection
    {
        return $this->connection;
    }
}

<?php

declare(strict_types=1);

/**
 * SQLite-specific schema grammar for DDL compilation.
 *
 * Uses double-quote identifier quoting, INTEGER PRIMARY KEY AUTOINCREMENT,
 * and handles SQLite's limited ALTER TABLE capabilities.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema\Grammar;

use PHPdot\Database\Exception\SchemaException;
use PHPdot\Database\Schema\Blueprint;
use PHPdot\Database\Schema\ColumnDefinition;
use PHPdot\Database\Schema\IndexDefinition;

final class SqliteSchemaGrammar extends SchemaGrammar
{
    /**
     * Wrap a column name in double quotes (SQLite quoting).
     *
     * @param string $column The column name
     *
     * @return string
     */
    public function wrapColumn(string $column): string
    {
        if ($column === '*') {
            return $column;
        }

        return '"' . str_replace('"', '""', $column) . '"';
    }

    /**
     * Compile a CREATE TABLE statement from a blueprint.
     *
     * @param Blueprint $blueprint The table blueprint
     */
    public function compileCreate(Blueprint $blueprint): string
    {
        $columns = [];
        foreach ($blueprint->getColumns() as $column) {
            $columns[] = $this->compileColumn($column);
        }

        $primaryColumns = [];
        foreach ($blueprint->getColumns() as $column) {
            if ($column->getAttribute('primary') === true && $column->getAttribute('autoIncrement') !== true) {
                /**
                 * @var string $colName
                 */
                $colName = $column->getAttribute('name', '');
                $primaryColumns[] = $colName;
            }
        }

        foreach ($blueprint->getIndexes() as $index) {
            if ($index->getType() === 'primary') {
                $idxColumns = $index->getColumns();
                $columnList = is_array($idxColumns) ? $idxColumns : [$idxColumns];
                $wrapped = implode(', ', array_map(fn(string $c): string => $this->wrapColumn($c), $columnList));
                $columns[] = 'PRIMARY KEY (' . $wrapped . ')';
            }
        }

        if ($primaryColumns !== [] && !$this->hasExplicitPrimaryIndex($blueprint)) {
            $wrapped = implode(', ', array_map(fn(string $c): string => $this->wrapColumn($c), $primaryColumns));
            $columns[] = 'PRIMARY KEY (' . $wrapped . ')';
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $columns[] = $this->compileForeignKey($foreignKey, $blueprint->getTable());
        }

        $prefix = $blueprint->isTemporary() ? 'CREATE TEMPORARY TABLE' : 'CREATE TABLE';
        $sql = $prefix . ' ' . $this->wrapTable($blueprint->getTable()) . " (\n    "
            . implode(",\n    ", $columns)
            . "\n)";

        return $sql;
    }

    /**
     * Emit non-primary indexes as standalone CREATE INDEX statements, since
     * SQLite cannot declare them inline in CREATE TABLE.
     *
     * @param Blueprint $blueprint The table blueprint
     *
     * @return list<string>
     */
    public function compileCreateIndexes(Blueprint $blueprint): array
    {
        return $this->buildStandaloneIndexes($blueprint);
    }

    /**
     * Compile a standalone index for SQLite. Full-text and spatial indexes are
     * not supported as ordinary indexes and fail fast.
     *
     * @param string $type The index type
     * @param string|null $name Optional index name
     * @param string $table The unprefixed table name
     * @param list<string> $columns The indexed columns
     * @param IndexDefinition|null $index The source index, when available
     */
    protected function compileCreateIndexStatement(string $type, ?string $name, string $table, array $columns, ?IndexDefinition $index = null): string
    {
        if ($type === 'fulltext' || $type === 'spatial') {
            throw SchemaException::unsupportedOperation($type . ' index', 'sqlite');
        }

        return parent::compileCreateIndexStatement($type, $name, $table, $columns, $index);
    }

    /**
     * Compile ALTER TABLE statements from a blueprint.
     *
     * SQLite supports ADD COLUMN, RENAME COLUMN, and (3.35+) DROP COLUMN.
     * Indexes are created and dropped with separate CREATE/DROP INDEX
     * statements rather than ALTER TABLE.
     *
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = [];
        $table = $this->wrapTable($blueprint->getTable());

        foreach ($blueprint->getColumns() as $column) {
            if ($column->getAttribute('change') === true) {
                throw SchemaException::unsupportedOperation('modifying an existing column (change)', 'sqlite');
            }

            $statements[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->compileColumn($column);
        }

        foreach ($this->buildStandaloneIndexes($blueprint) as $indexSql) {
            $statements[] = $indexSql;
        }

        foreach ($blueprint->getCommands() as $command) {
            foreach ($this->compileCommand($command, $blueprint->getTable(), $table) as $stmt) {
                $statements[] = $stmt;
            }
        }

        return $statements;
    }

    /**
     * Compile a deferred ALTER command that SQLite can honour.
     *
     * @param array{type: string, data: array<string, mixed>} $command The command
     * @param string $table The unprefixed table name
     * @param string $wrappedTable The quoted, prefixed table name
     *
     * @return list<string>
     */
    private function compileCommand(array $command, string $table, string $wrappedTable): array
    {
        return match ($command['type']) {
            'renameColumn' => $this->compileRenameColumn($wrappedTable, $command['data']),
            'dropColumn' => $this->compileDropColumns($wrappedTable, $command['data']),
            'dropIndex', 'dropUnique' => $this->compileDropIndex($command['data']),
            default => [],
        };
    }

    /**
     * Compile a RENAME COLUMN statement.
     *
     * @param string $wrappedTable The quoted table name
     * @param array<string, mixed> $data The command data
     *
     * @return list<string>
     */
    private function compileRenameColumn(string $wrappedTable, array $data): array
    {
        /**
         * @var string $from
         */
        $from = $data['from'] ?? '';
        /**
         * @var string $to
         */
        $to = $data['to'] ?? '';

        return ['ALTER TABLE ' . $wrappedTable . ' RENAME COLUMN ' . $this->wrapColumn($from) . ' TO ' . $this->wrapColumn($to)];
    }

    /**
     * Compile DROP COLUMN statements (SQLite 3.35+ supports one column per statement).
     *
     * @param string $wrappedTable The quoted table name
     * @param array<string, mixed> $data The command data
     *
     * @return list<string>
     */
    private function compileDropColumns(string $wrappedTable, array $data): array
    {
        /**
         * @var list<string> $columns
         */
        $columns = $data['columns'] ?? [];

        return array_map(
            fn(string $c): string => 'ALTER TABLE ' . $wrappedTable . ' DROP COLUMN ' . $this->wrapColumn($c),
            $columns,
        );
    }

    /**
     * Compile a DROP INDEX statement (SQLite drops indexes by name, not table).
     *
     * @param array<string, mixed> $data The command data
     *
     * @return list<string>
     */
    private function compileDropIndex(array $data): array
    {
        /**
         * @var string $name
         */
        $name = $data['name'] ?? '';

        return ['DROP INDEX IF EXISTS ' . $this->wrapColumn($name)];
    }

    /**
     * Compile a DROP TABLE statement.
     *
     * @param string $table The table name
     */
    public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->wrapTable($table);
    }

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     *
     * @param string $table The table name
     */
    public function compileDropIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->wrapTable($table);
    }

    /**
     * Compile a RENAME TABLE statement.
     *
     * @param string $from The current table name
     * @param string $to The new table name
     */
    public function compileRename(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($from) . ' RENAME TO ' . $this->wrapColumn($this->tablePrefix . $to);
    }

    /**
     * Compile the SQL type for a column definition.
     *
     * @param ColumnDefinition $column The column definition
     */
    protected function compileColumnType(ColumnDefinition $column): string
    {
        /**
         * @var string $type
         */
        $type = $column->getAttribute('type', '');

        return match ($type) {
            'bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger' => 'INTEGER',
            'float', 'double', 'decimal' => 'REAL',
            'string', 'char' => 'VARCHAR(' . $column->getIntAttribute('length', 255) . ')',
            'text', 'mediumText', 'longText' => 'TEXT',
            'boolean' => 'INTEGER',
            'date', 'dateTime', 'timestamp', 'time', 'year' => 'TEXT',
            'binary', 'blob' => 'BLOB',
            'json', 'jsonb' => 'TEXT',
            'uuid' => 'VARCHAR(36)',
            'ipAddress' => 'VARCHAR(45)',
            'macAddress' => 'VARCHAR(17)',
            'enum' => $this->compileEnumCheck($column),
            'set' => 'TEXT',
            default => strtoupper($type),
        };
    }

    /**
     * Compile an ENUM type as VARCHAR with a CHECK constraint so the allowed
     * values are actually enforced (SQLite has no native ENUM), matching the
     * enforcement MySQL and Postgres provide.
     *
     * @param ColumnDefinition $column The column definition
     *
     * @return string
     */
    private function compileEnumCheck(ColumnDefinition $column): string
    {
        /**
         * @var list<string> $allowed
         */
        $allowed = $column->getAttribute('allowed', []);
        /**
         * @var string $name
         */
        $name = $column->getAttribute('name', '');

        $values = implode(', ', array_map(
            static fn(string $v): string => "'" . str_replace("'", "''", $v) . "'",
            $allowed,
        ));

        return 'VARCHAR(255) CHECK (' . $this->wrapColumn($name) . ' IN (' . $values . '))';
    }

    /**
     * Compile column modifiers for SQLite.
     *
     * @param ColumnDefinition $column The column definition
     */
    protected function compileColumnModifiers(ColumnDefinition $column): string
    {
        $sql = '';

        if ($column->getAttribute('autoIncrement') === true && $column->getAttribute('primary') === true) {
            $sql .= ' PRIMARY KEY AUTOINCREMENT';

            return $sql;
        }

        /**
         * @var string|null $virtualAs
         */
        $virtualAs = $column->getAttribute('virtualAs');
        /**
         * @var string|null $storedAs
         */
        $storedAs = $column->getAttribute('storedAs');

        if ($virtualAs !== null) {
            return ' GENERATED ALWAYS AS (' . $virtualAs . ') VIRTUAL';
        }

        if ($storedAs !== null) {
            return ' GENERATED ALWAYS AS (' . $storedAs . ') STORED';
        }

        if ($column->getAttribute('nullable') === true) {
            $sql .= ' NULL';
        } elseif ($column->getAttribute('autoIncrement') !== true) {
            $sql .= ' NOT NULL';
        }

        if ($column->getAttribute('useCurrent') === true) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif (array_key_exists('default', $column->getAttributes())) {
            $sql .= ' DEFAULT ' . $this->getDefaultValue($column->getAttribute('default'));
        }

        return $sql;
    }

    /**
     * Check if the blueprint has an explicit primary index.
     *
     * @param Blueprint $blueprint The blueprint to check
     *
     * @return bool
     */
    private function hasExplicitPrimaryIndex(Blueprint $blueprint): bool
    {
        foreach ($blueprint->getIndexes() as $index) {
            if ($index->getType() === 'primary') {
                return true;
            }
        }

        return false;
    }
}

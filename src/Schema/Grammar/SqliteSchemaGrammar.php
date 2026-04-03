<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema\Grammar;

use PHPdot\Database\Schema\Blueprint;
use PHPdot\Database\Schema\ColumnDefinition;

/**
 * SQLite-specific schema grammar for DDL compilation.
 *
 * Uses double-quote identifier quoting, INTEGER PRIMARY KEY AUTOINCREMENT,
 * and handles SQLite's limited ALTER TABLE capabilities.
 */
final class SqliteSchemaGrammar extends SchemaGrammar
{
    /**
     * Wrap a column name in double quotes (SQLite quoting).
     *
     * @param string $column The column name
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

        // Collect primary key columns from column definitions (skip single auto-increment PKs handled inline)
        $primaryColumns = [];
        foreach ($blueprint->getColumns() as $column) {
            if ($column->getAttribute('primary') === true && $column->getAttribute('autoIncrement') !== true) {
                /** @var string $colName */
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
     * Compile ALTER TABLE statements from a blueprint.
     *
     * SQLite has limited ALTER TABLE support (ADD COLUMN, RENAME COLUMN).
     *
     * @param Blueprint $blueprint The table blueprint
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = [];
        $table = $this->wrapTable($blueprint->getTable());

        foreach ($blueprint->getColumns() as $column) {
            if ($column->getAttribute('change') !== true) {
                $statements[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->compileColumn($column);
            }
        }

        // SQLite 3.25+ supports RENAME COLUMN
        foreach ($blueprint->getCommands() as $command) {
            if ($command['type'] === 'renameColumn') {
                /** @var string $from */
                $from = $command['data']['from'] ?? '';
                /** @var string $to */
                $to = $command['data']['to'] ?? '';
                $statements[] = 'ALTER TABLE ' . $table . ' RENAME COLUMN '
                    . $this->wrapColumn($from) . ' TO ' . $this->wrapColumn($to);
            }
        }

        return $statements;
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
        /** @var string $type */
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
            'enum' => 'VARCHAR(255)',
            'set' => 'TEXT',
            default => strtoupper($type),
        };
    }

    /**
     * Compile column modifiers for SQLite.
     *
     * @param ColumnDefinition $column The column definition
     */
    protected function compileColumnModifiers(ColumnDefinition $column): string
    {
        $sql = '';

        // SQLite: INTEGER PRIMARY KEY AUTOINCREMENT is handled inline
        if ($column->getAttribute('autoIncrement') === true && $column->getAttribute('primary') === true) {
            $sql .= ' PRIMARY KEY AUTOINCREMENT';

            return $sql;
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

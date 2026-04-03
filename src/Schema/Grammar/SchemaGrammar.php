<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema\Grammar;

use PHPdot\Database\Schema\Blueprint;
use PHPdot\Database\Schema\ColumnDefinition;
use PHPdot\Database\Schema\ForeignKeyDefinition;
use PHPdot\Database\Schema\IndexDefinition;

/**
 * Abstract base class for DDL (schema) compilation.
 *
 * Each database driver extends this class to handle dialect-specific
 * CREATE TABLE, ALTER TABLE, DROP TABLE, and related syntax.
 */
abstract class SchemaGrammar
{
    protected string $tablePrefix = '';

    // ---------------------------------------------------------------
    //  Abstract Methods
    // ---------------------------------------------------------------

    /**
     * Compile a CREATE TABLE statement from a blueprint.
     *
     * @param Blueprint $blueprint The table blueprint
     */
    abstract public function compileCreate(Blueprint $blueprint): string;

    /**
     * Compile ALTER TABLE statements from a blueprint.
     *
     * Returns an array because a single ALTER may require multiple statements.
     *
     * @param Blueprint $blueprint The table blueprint
     * @return list<string>
     */
    abstract public function compileAlter(Blueprint $blueprint): array;

    /**
     * Compile a DROP TABLE statement.
     *
     * @param string $table The table name
     */
    abstract public function compileDrop(string $table): string;

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     *
     * @param string $table The table name
     */
    abstract public function compileDropIfExists(string $table): string;

    /**
     * Compile a RENAME TABLE statement.
     *
     * @param string $from The current table name
     * @param string $to The new table name
     */
    abstract public function compileRename(string $from, string $to): string;

    /**
     * Compile the SQL type for a column definition.
     *
     * @param ColumnDefinition $column The column definition
     */
    abstract protected function compileColumnType(ColumnDefinition $column): string;

    /**
     * Compile column modifiers (NULL, DEFAULT, AUTO_INCREMENT, etc.).
     *
     * @param ColumnDefinition $column The column definition
     */
    abstract protected function compileColumnModifiers(ColumnDefinition $column): string;

    // ---------------------------------------------------------------
    //  Public Helpers
    // ---------------------------------------------------------------

    /**
     * Set the table prefix for all compiled statements.
     *
     * @param string $prefix The table name prefix
     */
    public function setTablePrefix(string $prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    /**
     * Wrap a table name with the table prefix and quoting.
     *
     * @param string $table The table name
     */
    public function wrapTable(string $table): string
    {
        return $this->wrapColumn($this->tablePrefix . $table);
    }

    /**
     * Wrap a column name in identifier quotes.
     *
     * @param string $column The column name
     */
    public function wrapColumn(string $column): string
    {
        if ($column === '*') {
            return $column;
        }

        return '`' . str_replace('`', '``', $column) . '`';
    }

    // ---------------------------------------------------------------
    //  Protected Helpers
    // ---------------------------------------------------------------

    /**
     * Compile a single column definition (type + modifiers).
     *
     * @param ColumnDefinition $column The column definition
     */
    protected function compileColumn(ColumnDefinition $column): string
    {
        /** @var string $name */
        $name = $column->getAttribute('name', '');

        return $this->wrapColumn($name) . ' ' . $this->compileColumnType($column) . $this->compileColumnModifiers($column);
    }

    /**
     * Compile an index definition into SQL.
     *
     * @param IndexDefinition $index The index definition
     * @param string $table The table name (used for auto-generated index names)
     */
    protected function compileIndex(IndexDefinition $index, string $table): string
    {
        $columns = $index->getColumns();
        $columnList = is_array($columns) ? $columns : [$columns];
        $wrappedColumns = implode(', ', array_map(fn(string $c): string => $this->wrapColumn($c), $columnList));

        $name = $index->getName() ?? $this->generateIndexName($table, $columnList, $index->getType());

        return match ($index->getType()) {
            'primary' => 'PRIMARY KEY (' . $wrappedColumns . ')',
            'unique' => 'UNIQUE INDEX ' . $this->wrapColumn($name) . ' (' . $wrappedColumns . ')',
            'fulltext' => 'FULLTEXT INDEX ' . $this->wrapColumn($name) . ' (' . $wrappedColumns . ')',
            'spatial' => 'SPATIAL INDEX ' . $this->wrapColumn($name) . ' (' . $wrappedColumns . ')',
            default => 'INDEX ' . $this->wrapColumn($name) . ' (' . $wrappedColumns . ')',
        };
    }

    /**
     * Compile a foreign key definition into SQL.
     *
     * @param ForeignKeyDefinition $foreignKey The foreign key definition
     * @param string $table The table name (used for auto-generated constraint names)
     */
    protected function compileForeignKey(ForeignKeyDefinition $foreignKey, string $table): string
    {
        $column = $foreignKey->getColumn();
        $referencedColumns = $foreignKey->getReferencedColumns();
        $refColumnList = is_array($referencedColumns) ? $referencedColumns : [$referencedColumns];

        $constraintName = $this->tablePrefix . $table . '_' . $column . '_foreign';

        $sql = 'CONSTRAINT ' . $this->wrapColumn($constraintName)
            . ' FOREIGN KEY (' . $this->wrapColumn($column) . ')'
            . ' REFERENCES ' . $this->wrapTable($foreignKey->getReferencedTable())
            . ' (' . implode(', ', array_map(fn(string $c): string => $this->wrapColumn($c), $refColumnList)) . ')';

        if ($foreignKey->getOnDelete() !== '') {
            $sql .= ' ON DELETE ' . $foreignKey->getOnDelete();
        }

        if ($foreignKey->getOnUpdate() !== '') {
            $sql .= ' ON UPDATE ' . $foreignKey->getOnUpdate();
        }

        return $sql;
    }

    /**
     * Generate an index name from the table, columns, and type.
     *
     * @param string $table The table name
     * @param list<string> $columns The indexed columns
     * @param string $type The index type
     */
    protected function generateIndexName(string $table, array $columns, string $type): string
    {
        return strtolower($this->tablePrefix . $table . '_' . implode('_', $columns) . '_' . $type);
    }

    /**
     * Get a default value SQL representation.
     *
     * @param mixed $value The default value
     */
    protected function getDefaultValue(mixed $value): string
    {
        if ($value === true) {
            return '1';
        }

        if ($value === false) {
            return '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return "'" . str_replace("'", "''", (string) json_encode($value)) . "'";
    }
}

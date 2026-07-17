<?php

declare(strict_types=1);

/**
 * Abstract base class for DDL (schema) compilation.
 *
 * Each database driver extends this class to handle dialect-specific
 * CREATE TABLE, ALTER TABLE, DROP TABLE, and related syntax.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema\Grammar;

use PHPdot\Database\Schema\Blueprint;
use PHPdot\Database\Schema\ColumnDefinition;
use PHPdot\Database\Schema\ForeignKeyDefinition;
use PHPdot\Database\Schema\IndexDefinition;

abstract class SchemaGrammar
{
    protected string $tablePrefix = '';

    /**
     * Compile a CREATE TABLE statement from a blueprint.
     *
     * @param Blueprint $blueprint The table blueprint
     *
     * @return string
     */
    abstract public function compileCreate(Blueprint $blueprint): string;

    /**
     * Compile ALTER TABLE statements from a blueprint.
     *
     * Returns an array because a single ALTER may require multiple statements.
     *
     * @param Blueprint $blueprint The table blueprint
     *
     * @return list<string>
     */
    abstract public function compileAlter(Blueprint $blueprint): array;

    /**
     * Compile a DROP TABLE statement.
     *
     * @param string $table The table name
     *
     * @return string
     */
    abstract public function compileDrop(string $table): string;

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     *
     * @param string $table The table name
     *
     * @return string
     */
    abstract public function compileDropIfExists(string $table): string;

    /**
     * Compile a RENAME TABLE statement.
     *
     * @param string $from The current table name
     * @param string $to The new table name
     *
     * @return string
     */
    abstract public function compileRename(string $from, string $to): string;

    /**
     * Compile the SQL type for a column definition.
     *
     * @param ColumnDefinition $column The column definition
     *
     * @return string
     */
    abstract protected function compileColumnType(ColumnDefinition $column): string;

    /**
     * Compile column modifiers (NULL, DEFAULT, AUTO_INCREMENT, etc.).
     *
     * @param ColumnDefinition $column The column definition
     *
     * @return string
     */
    abstract protected function compileColumnModifiers(ColumnDefinition $column): string;

    /**
     * Set the table prefix for all compiled statements.
     *
     * @param string $prefix The table name prefix
     *
     * @return void
     */
    public function setTablePrefix(string $prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    /**
     * Wrap a table name with the table prefix and quoting.
     *
     * @param string $table The table name
     *
     * @return string
     */
    public function wrapTable(string $table): string
    {
        return $this->wrapColumn($this->tablePrefix . $table);
    }

    /**
     * Wrap a column name in identifier quotes.
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

        return '`' . str_replace('`', '``', $column) . '`';
    }

    /**
     * Compile a single column definition (type + modifiers).
     *
     * @param ColumnDefinition $column The column definition
     *
     * @return string
     */
    protected function compileColumn(ColumnDefinition $column): string
    {
        /**
         * @var string $name
         */
        $name = $column->getAttribute('name', '');

        return $this->wrapColumn($name) . ' ' . $this->compileColumnType($column) . $this->compileColumnModifiers($column);
    }

    /**
     * Compile an index definition into SQL.
     *
     * @param IndexDefinition $index The index definition
     * @param string $table The table name (used for auto-generated index names)
     *
     * @return string
     */
    protected function compileIndex(IndexDefinition $index, string $table): string
    {
        $columns = $index->getColumns();
        $columnList = is_array($columns) ? $columns : [$columns];
        $wrappedColumns = implode(', ', array_map(fn(string $c): string => $this->wrapColumn($c), $columnList));

        $name = $index->getName() ?? $this->generateIndexName($table, $columnList, $index->getType());
        $using = $index->getAlgorithm() !== '' ? ' USING ' . $index->getAlgorithm() : '';

        return match ($index->getType()) {
            'primary' => 'PRIMARY KEY (' . $wrappedColumns . ')',
            'unique' => 'UNIQUE INDEX ' . $this->wrapColumn($name) . ' (' . $wrappedColumns . ')' . $using,
            'fulltext' => 'FULLTEXT INDEX ' . $this->wrapColumn($name) . ' (' . $wrappedColumns . ')',
            'spatial' => 'SPATIAL INDEX ' . $this->wrapColumn($name) . ' (' . $wrappedColumns . ')',
            default => 'INDEX ' . $this->wrapColumn($name) . ' (' . $wrappedColumns . ')' . $using,
        };
    }

    /**
     * Compile a foreign key definition into SQL.
     *
     * @param ForeignKeyDefinition $foreignKey The foreign key definition
     * @param string $table The table name (used for auto-generated constraint names)
     *
     * @return string
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
     *
     * @return string
     */
    protected function generateIndexName(string $table, array $columns, string $type): string
    {
        return strtolower($this->tablePrefix . $table . '_' . implode('_', $columns) . '_' . $type);
    }

    /**
     * Compile extra statements to run immediately after CREATE TABLE.
     *
     * Dialects that can declare every index inline in CREATE TABLE (MySQL)
     * return an empty list; dialects that cannot (Postgres, SQLite) return
     * standalone CREATE INDEX statements so declared indexes are never dropped.
     *
     * @param Blueprint $blueprint The table blueprint
     *
     * @return list<string>
     */
    public function compileCreateIndexes(Blueprint $blueprint): array
    {
        return [];
    }

    /**
     * Build standalone CREATE [UNIQUE] INDEX statements for a table's
     * non-primary indexes and its column-level unique()/index() modifiers.
     *
     * @param Blueprint $blueprint The table blueprint
     *
     * @return list<string>
     */
    protected function buildStandaloneIndexes(Blueprint $blueprint): array
    {
        $table = $blueprint->getTable();
        $statements = [];

        foreach ($blueprint->getIndexes() as $index) {
            if ($index->getType() === 'primary') {
                continue;
            }

            $columns = $index->getColumns();
            $columnList = is_array($columns) ? $columns : [$columns];
            $statements[] = $this->compileCreateIndexStatement($index->getType(), $index->getName(), $table, $columnList, $index);
        }

        foreach ($blueprint->getColumns() as $column) {
            /**
             * @var string $colName
             */
            $colName = $column->getAttribute('name', '');

            if ($column->getAttribute('unique') === true) {
                $statements[] = $this->compileCreateIndexStatement('unique', null, $table, [$colName]);
            }

            if ($column->getAttribute('index') === true) {
                $statements[] = $this->compileCreateIndexStatement('index', null, $table, [$colName]);
            }
        }

        return $statements;
    }

    /**
     * Compile a single standalone CREATE [UNIQUE] INDEX statement.
     *
     * @param string $type The index type; 'unique' emits a UNIQUE index
     * @param string|null $name Optional index name (auto-generated when null)
     * @param string $table The unprefixed table name
     * @param list<string> $columns The indexed columns
     * @param IndexDefinition|null $index The source index, when available (for algorithm/language)
     *
     * @return string
     */
    protected function compileCreateIndexStatement(string $type, ?string $name, string $table, array $columns, ?IndexDefinition $index = null): string
    {
        $indexName = $name ?? $this->generateIndexName($table, $columns, $type);
        $wrappedColumns = implode(', ', array_map(fn(string $c): string => $this->wrapColumn($c), $columns));
        $unique = $type === 'unique' ? 'UNIQUE ' : '';

        return 'CREATE ' . $unique . 'INDEX ' . $this->wrapColumn($indexName)
            . ' ON ' . $this->wrapTable($table) . ' (' . $wrappedColumns . ')';
    }

    /**
     * Get a default value SQL representation.
     *
     * @param mixed $value The default value
     *
     * @return string
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
            return $this->quoteString($value);
        }

        return $this->quoteString((string) json_encode($value));
    }

    /**
     * Quote a string as a SQL literal.
     *
     * Escapes single quotes per the SQL standard. Dialects with additional
     * escape characters (e.g. MySQL's backslash) override this.
     *
     * @param string $value The raw string value
     *
     * @return string
     */
    protected function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

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
 * PostgreSQL-specific schema grammar for DDL compilation.
 *
 * Uses double-quote identifier quoting, SERIAL/BIGSERIAL for auto-increment,
 * native BOOLEAN, JSONB, and PostgreSQL-specific ALTER TABLE syntax.
 */
final class PostgresSchemaGrammar extends SchemaGrammar
{
    /**
     * Wrap a column name in double quotes (PostgreSQL quoting).
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

        // Collect primary key columns from column definitions
        $primaryColumns = [];
        foreach ($blueprint->getColumns() as $column) {
            if ($column->getAttribute('primary') === true) {
                /** @var string $colName */
                $colName = $column->getAttribute('name', '');
                $primaryColumns[] = $colName;
            }
        }

        foreach ($blueprint->getIndexes() as $index) {
            if ($index->getType() === 'primary') {
                $columns[] = $this->compileIndex($index, $blueprint->getTable());
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
     * @param Blueprint $blueprint The table blueprint
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = [];
        $table = $this->wrapTable($blueprint->getTable());

        foreach ($blueprint->getColumns() as $column) {
            if ($column->getAttribute('change') === true) {
                /** @var string $colName */
                $colName = $column->getAttribute('name', '');
                $statements[] = 'ALTER TABLE ' . $table . ' ALTER COLUMN ' . $this->wrapColumn($colName)
                    . ' TYPE ' . $this->compileColumnType($column);
            } else {
                $statements[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->compileColumn($column);
            }
        }

        // Unique indexes need CREATE INDEX
        foreach ($blueprint->getIndexes() as $index) {
            $indexColumns = $index->getColumns();
            $columnList = is_array($indexColumns) ? $indexColumns : [$indexColumns];
            $wrappedColumns = implode(', ', array_map(fn(string $c): string => $this->wrapColumn($c), $columnList));
            $name = $index->getName() ?? $this->generateIndexName($blueprint->getTable(), $columnList, $index->getType());

            if ($index->getType() === 'unique') {
                $statements[] = 'CREATE UNIQUE INDEX ' . $this->wrapColumn($name) . ' ON ' . $table . ' (' . $wrappedColumns . ')';
            } elseif ($index->getType() !== 'primary') {
                $statements[] = 'CREATE INDEX ' . $this->wrapColumn($name) . ' ON ' . $table . ' (' . $wrappedColumns . ')';
            }
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $statements[] = 'ALTER TABLE ' . $table . ' ADD ' . $this->compileForeignKey($foreignKey, $blueprint->getTable());
        }

        foreach ($blueprint->getCommands() as $command) {
            $compiled = $this->compileCommand($command, $blueprint->getTable());
            foreach ($compiled as $stmt) {
                $statements[] = $stmt;
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
        $isAutoIncrement = $column->getAttribute('autoIncrement') === true;

        return match ($type) {
            'bigInteger' => $isAutoIncrement ? 'BIGSERIAL' : 'BIGINT',
            'integer' => $isAutoIncrement ? 'SERIAL' : 'INTEGER',
            'mediumInteger' => $isAutoIncrement ? 'SERIAL' : 'INTEGER',
            'smallInteger' => $isAutoIncrement ? 'SMALLSERIAL' : 'SMALLINT',
            'tinyInteger' => $isAutoIncrement ? 'SMALLSERIAL' : 'SMALLINT',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'decimal' => 'DECIMAL(' . $column->getIntAttribute('precision', 8) . ', ' . $column->getIntAttribute('scale', 2) . ')',
            'string' => 'VARCHAR(' . $column->getIntAttribute('length', 255) . ')',
            'char' => 'CHAR(' . $column->getIntAttribute('length', 255) . ')',
            'text', 'mediumText', 'longText' => 'TEXT',
            'boolean' => 'BOOLEAN',
            'date' => 'DATE',
            'dateTime' => $this->compileDateTimePrecision('TIMESTAMP', $column),
            'timestamp' => $this->compileDateTimePrecision('TIMESTAMP', $column),
            'time' => $this->compileDateTimePrecision('TIME', $column),
            'year' => 'INTEGER',
            'binary' => 'BYTEA',
            'blob' => 'BYTEA',
            'json' => 'JSON',
            'jsonb' => 'JSONB',
            'uuid' => 'UUID',
            'ipAddress' => 'INET',
            'macAddress' => 'MACADDR',
            'enum' => $this->compileEnumCheck($column),
            'set' => 'TEXT',
            default => strtoupper($type),
        };
    }

    /**
     * Compile column modifiers for PostgreSQL.
     *
     * @param ColumnDefinition $column The column definition
     */
    protected function compileColumnModifiers(ColumnDefinition $column): string
    {
        $sql = '';

        // Generated columns
        /** @var string|null $virtualAs */
        $virtualAs = $column->getAttribute('virtualAs');
        /** @var string|null $storedAs */
        $storedAs = $column->getAttribute('storedAs');

        if ($storedAs !== null) {
            $sql .= ' GENERATED ALWAYS AS (' . $storedAs . ') STORED';
        }

        if ($virtualAs === null && $storedAs === null) {
            if ($column->getAttribute('nullable') === true) {
                $sql .= ' NULL';
            } elseif ($column->getAttribute('autoIncrement') !== true) {
                $sql .= ' NOT NULL';
            }
        }

        if ($column->getAttribute('useCurrent') === true) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif (array_key_exists('default', $column->getAttributes())) {
            $sql .= ' DEFAULT ' . $this->getDefaultValue($column->getAttribute('default'));
        }

        return $sql;
    }

    // ---------------------------------------------------------------
    //  Internal Helpers
    // ---------------------------------------------------------------

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

    /**
     * Compile a datetime type with optional precision.
     *
     * @param string $type The base type
     * @param ColumnDefinition $column The column definition
     */
    private function compileDateTimePrecision(string $type, ColumnDefinition $column): string
    {
        $precision = $column->getIntAttribute('precision', 0);

        if ($precision > 0) {
            return $type . '(' . $precision . ')';
        }

        return $type;
    }

    /**
     * Compile an ENUM type as VARCHAR with CHECK constraint.
     *
     * @param ColumnDefinition $column The column definition
     */
    private function compileEnumCheck(ColumnDefinition $column): string
    {
        /** @var list<string> $allowed */
        $allowed = $column->getAttribute('allowed', []);
        /** @var string $name */
        $name = $column->getAttribute('name', '');

        $values = implode(', ', array_map(
            static fn(string $v): string => "'" . str_replace("'", "''", $v) . "'",
            $allowed,
        ));

        return 'VARCHAR(255) CHECK (' . $this->wrapColumn($name) . ' IN (' . $values . '))';
    }

    /**
     * Compile a DDL command.
     *
     * @param array{type: string, data: array<string, mixed>} $command The command
     * @param string $table The table name
     * @return list<string>
     */
    private function compileCommand(array $command, string $table): array
    {
        $wrappedTable = $this->wrapTable($table);

        return match ($command['type']) {
            'dropColumn' => $this->compileDropColumns($wrappedTable, $command['data']),
            'renameColumn' => $this->compileRenameColumn($wrappedTable, $command['data']),
            'dropIndex', 'dropUnique' => $this->compileDropIndexPg($command['data']),
            'dropPrimary' => ['ALTER TABLE ' . $wrappedTable . ' DROP CONSTRAINT ' . $this->wrapColumn($this->tablePrefix . $table . '_pkey')],
            'dropForeign' => $this->compileDropForeignPg($wrappedTable, $command['data']),
            default => [],
        };
    }

    /**
     * Compile DROP COLUMN statements.
     *
     * @param string $wrappedTable The quoted table name
     * @param array<string, mixed> $data The command data
     * @return list<string>
     */
    private function compileDropColumns(string $wrappedTable, array $data): array
    {
        /** @var list<string> $columns */
        $columns = $data['columns'] ?? [];
        $parts = array_map(fn(string $c): string => 'DROP COLUMN ' . $this->wrapColumn($c), $columns);

        if ($parts === []) {
            return [];
        }

        return ['ALTER TABLE ' . $wrappedTable . ' ' . implode(', ', $parts)];
    }

    /**
     * Compile a RENAME COLUMN statement.
     *
     * @param string $wrappedTable The quoted table name
     * @param array<string, mixed> $data The command data
     * @return list<string>
     */
    private function compileRenameColumn(string $wrappedTable, array $data): array
    {
        /** @var string $from */
        $from = $data['from'] ?? '';
        /** @var string $to */
        $to = $data['to'] ?? '';

        return ['ALTER TABLE ' . $wrappedTable . ' RENAME COLUMN ' . $this->wrapColumn($from) . ' TO ' . $this->wrapColumn($to)];
    }

    /**
     * Compile a DROP INDEX statement for PostgreSQL.
     *
     * @param array<string, mixed> $data The command data
     * @return list<string>
     */
    private function compileDropIndexPg(array $data): array
    {
        /** @var string $name */
        $name = $data['name'] ?? '';

        return ['DROP INDEX ' . $this->wrapColumn($name)];
    }

    /**
     * Compile a DROP CONSTRAINT (foreign key) statement for PostgreSQL.
     *
     * @param string $wrappedTable The quoted table name
     * @param array<string, mixed> $data The command data
     * @return list<string>
     */
    private function compileDropForeignPg(string $wrappedTable, array $data): array
    {
        /** @var string $name */
        $name = $data['name'] ?? '';

        return ['ALTER TABLE ' . $wrappedTable . ' DROP CONSTRAINT ' . $this->wrapColumn($name)];
    }
}

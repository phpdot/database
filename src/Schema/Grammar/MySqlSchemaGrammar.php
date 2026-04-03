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
 * MySQL-specific schema grammar for DDL compilation.
 *
 * Handles backtick quoting, MySQL-specific column types (BIGINT UNSIGNED,
 * ENUM, SET), AUTO_INCREMENT, ENGINE, CHARSET, COLLATION, and COMMENT options.
 */
final class MySqlSchemaGrammar extends SchemaGrammar
{
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
            if ($column->getAttribute('primary') === true) {
                /** @var string $colName */
                $colName = $column->getAttribute('name', '');
                $primaryColumns[] = $colName;
            }
        }

        foreach ($blueprint->getIndexes() as $index) {
            $columns[] = $this->compileIndex($index, $blueprint->getTable());
        }

        if ($primaryColumns !== [] && !$this->hasExplicitPrimaryIndex($blueprint)) {
            $wrapped = implode(', ', array_map(fn(string $c): string => $this->wrapColumn($c), $primaryColumns));
            $columns[] = 'PRIMARY KEY (' . $wrapped . ')';
        }

        foreach ($blueprint->getColumns() as $column) {
            /** @var string $colName */
            $colName = $column->getAttribute('name', '');

            if ($column->getAttribute('unique') === true) {
                $name = $this->generateIndexName($blueprint->getTable(), [$colName], 'unique');
                $columns[] = 'UNIQUE INDEX ' . $this->wrapColumn($name) . ' (' . $this->wrapColumn($colName) . ')';
            }

            if ($column->getAttribute('index') === true) {
                $name = $this->generateIndexName($blueprint->getTable(), [$colName], 'index');
                $columns[] = 'INDEX ' . $this->wrapColumn($name) . ' (' . $this->wrapColumn($colName) . ')';
            }
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $columns[] = $this->compileForeignKey($foreignKey, $blueprint->getTable());
        }

        $prefix = $blueprint->isTemporary() ? 'CREATE TEMPORARY TABLE' : 'CREATE TABLE';
        $sql = $prefix . ' ' . $this->wrapTable($blueprint->getTable()) . " (\n    "
            . implode(",\n    ", $columns)
            . "\n)";

        $sql .= $this->compileTableOptions($blueprint);

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
                $statements[] = 'ALTER TABLE ' . $table . ' MODIFY COLUMN ' . $this->compileColumn($column);
            } else {
                $addSql = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->compileColumn($column);

                /** @var string|null $after */
                $after = $column->getAttribute('after');
                if ($after !== null) {
                    $addSql .= ' AFTER ' . $this->wrapColumn($after);
                } elseif ($column->getAttribute('first') === true) {
                    $addSql .= ' FIRST';
                }

                $statements[] = $addSql;
            }
        }

        foreach ($blueprint->getIndexes() as $index) {
            $statements[] = 'ALTER TABLE ' . $table . ' ADD ' . $this->compileIndex($index, $blueprint->getTable());
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $statements[] = 'ALTER TABLE ' . $table . ' ADD ' . $this->compileForeignKey($foreignKey, $blueprint->getTable());
        }

        foreach ($blueprint->getCommands() as $command) {
            $compiled = $this->compileCommand($command, $blueprint->getTable());
            if ($compiled !== []) {
                foreach ($compiled as $stmt) {
                    $statements[] = $stmt;
                }
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
        return 'RENAME TABLE ' . $this->wrapTable($from) . ' TO ' . $this->wrapTable($to);
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
            'bigInteger' => 'BIGINT',
            'integer' => 'INT',
            'mediumInteger' => 'MEDIUMINT',
            'smallInteger' => 'SMALLINT',
            'tinyInteger' => 'TINYINT',
            'float' => 'FLOAT(' . $column->getIntAttribute('precision', 8) . ', ' . $column->getIntAttribute('scale', 2) . ')',
            'double' => 'DOUBLE(' . $column->getIntAttribute('precision', 16) . ', ' . $column->getIntAttribute('scale', 8) . ')',
            'decimal' => 'DECIMAL(' . $column->getIntAttribute('precision', 8) . ', ' . $column->getIntAttribute('scale', 2) . ')',
            'string' => 'VARCHAR(' . $column->getIntAttribute('length', 255) . ')',
            'char' => 'CHAR(' . $column->getIntAttribute('length', 255) . ')',
            'text' => 'TEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'boolean' => 'TINYINT(1)',
            'date' => 'DATE',
            'dateTime' => $this->compileDateTimePrecision('DATETIME', $column),
            'timestamp' => $this->compileDateTimePrecision('TIMESTAMP', $column),
            'time' => $this->compileDateTimePrecision('TIME', $column),
            'year' => 'YEAR',
            'binary' => 'BINARY(' . $column->getIntAttribute('length', 255) . ')',
            'blob' => 'BLOB',
            'json' => 'JSON',
            'jsonb' => 'JSON',
            'uuid' => 'CHAR(36)',
            'ipAddress' => 'VARCHAR(45)',
            'macAddress' => 'VARCHAR(17)',
            'enum' => $this->compileEnumType($column),
            'set' => $this->compileSetType($column),
            default => strtoupper($type),
        };
    }

    /**
     * Compile column modifiers (UNSIGNED, NULL, DEFAULT, AUTO_INCREMENT, etc.).
     *
     * @param ColumnDefinition $column The column definition
     */
    protected function compileColumnModifiers(ColumnDefinition $column): string
    {
        $sql = '';

        if ($column->getAttribute('unsigned') === true) {
            $sql .= ' UNSIGNED';
        }

        $charset = $column->getStringAttribute('charset');
        if ($charset !== '') {
            $sql .= ' CHARACTER SET ' . $charset;
        }

        $collation = $column->getStringAttribute('collation');
        if ($collation !== '') {
            $sql .= ' COLLATE ' . $collation;
        }

        /** @var string|null $virtualAs */
        $virtualAs = $column->getAttribute('virtualAs');
        /** @var string|null $storedAs */
        $storedAs = $column->getAttribute('storedAs');

        if ($virtualAs !== null) {
            $sql .= ' GENERATED ALWAYS AS (' . $virtualAs . ') VIRTUAL';
        } elseif ($storedAs !== null) {
            $sql .= ' GENERATED ALWAYS AS (' . $storedAs . ') STORED';
        }

        if ($virtualAs === null && $storedAs === null) {
            if ($column->getAttribute('nullable') === true) {
                $sql .= ' NULL';
            } else {
                $sql .= ' NOT NULL';
            }
        }

        if ($column->getAttribute('useCurrent') === true) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif (array_key_exists('default', $column->getAttributes())) {
            $sql .= ' DEFAULT ' . $this->getDefaultValue($column->getAttribute('default'));
        }

        if ($column->getAttribute('useCurrentOnUpdate') === true) {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        if ($column->getAttribute('autoIncrement') === true) {
            $sql .= ' AUTO_INCREMENT';
        }

        /** @var string|null $comment */
        $comment = $column->getAttribute('comment');
        if ($comment !== null) {
            $sql .= " COMMENT '" . str_replace("'", "''", $comment) . "'";
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

    /**
     * Compile table options (ENGINE, CHARSET, COLLATION, COMMENT).
     *
     * @param Blueprint $blueprint The blueprint
     */
    private function compileTableOptions(Blueprint $blueprint): string
    {
        $options = '';

        if ($blueprint->getEngine() !== '') {
            $options .= ' ENGINE = ' . $blueprint->getEngine();
        }

        if ($blueprint->getCharset() !== '') {
            $options .= ' DEFAULT CHARACTER SET ' . $blueprint->getCharset();
        }

        if ($blueprint->getCollation() !== '') {
            $options .= ' COLLATE ' . $blueprint->getCollation();
        }

        if ($blueprint->getTableComment() !== '') {
            $options .= " COMMENT = '" . str_replace("'", "''", $blueprint->getTableComment()) . "'";
        }

        return $options;
    }

    /**
     * Compile a datetime type with optional precision.
     *
     * @param string $type The base type (DATETIME, TIMESTAMP, TIME)
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
     * Compile an ENUM type with allowed values.
     *
     * @param ColumnDefinition $column The column definition
     */
    private function compileEnumType(ColumnDefinition $column): string
    {
        /** @var list<string> $allowed */
        $allowed = $column->getAttribute('allowed', []);
        $values = implode(', ', array_map(
            static fn(string $v): string => "'" . str_replace("'", "''", $v) . "'",
            $allowed,
        ));

        return 'ENUM(' . $values . ')';
    }

    /**
     * Compile a SET type with allowed values.
     *
     * @param ColumnDefinition $column The column definition
     */
    private function compileSetType(ColumnDefinition $column): string
    {
        /** @var list<string> $allowed */
        $allowed = $column->getAttribute('allowed', []);
        $values = implode(', ', array_map(
            static fn(string $v): string => "'" . str_replace("'", "''", $v) . "'",
            $allowed,
        ));

        return 'SET(' . $values . ')';
    }

    /**
     * Compile a DDL command (dropColumn, renameColumn, dropIndex, etc.).
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
            'dropIndex', 'dropUnique' => $this->compileDropIndex($wrappedTable, $command['data']),
            'dropPrimary' => ['ALTER TABLE ' . $wrappedTable . ' DROP PRIMARY KEY'],
            'dropForeign' => $this->compileDropForeignKey($wrappedTable, $command['data']),
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
     * Compile a DROP INDEX statement.
     *
     * @param string $wrappedTable The quoted table name
     * @param array<string, mixed> $data The command data
     * @return list<string>
     */
    private function compileDropIndex(string $wrappedTable, array $data): array
    {
        /** @var string $name */
        $name = $data['name'] ?? '';

        return ['ALTER TABLE ' . $wrappedTable . ' DROP INDEX ' . $this->wrapColumn($name)];
    }

    /**
     * Compile a DROP FOREIGN KEY statement.
     *
     * @param string $wrappedTable The quoted table name
     * @param array<string, mixed> $data The command data
     * @return list<string>
     */
    private function compileDropForeignKey(string $wrappedTable, array $data): array
    {
        /** @var string $name */
        $name = $data['name'] ?? '';

        return ['ALTER TABLE ' . $wrappedTable . ' DROP FOREIGN KEY ' . $this->wrapColumn($name)];
    }
}

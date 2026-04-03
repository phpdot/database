<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query\Grammar;

/**
 * SQLite query grammar.
 *
 * Overrides the base Grammar for SQLite-specific SQL dialect:
 * double-quote identifier quoting, INSERT OR IGNORE syntax, DELETE-based
 * truncation, strftime-based date extraction, and limited JSON support.
 */
final class SqliteGrammar extends Grammar
{
    /**
     * Wrap a single column or identifier segment in double quotes.
     *
     * SQLite uses double-quote quoting instead of backticks.
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
     * Compile an INSERT statement that returns the auto-increment ID.
     *
     * SQLite 3.35+ supports the RETURNING clause.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     * @param string $sequence The name of the sequence column
     */
    public function compileInsertGetId(string $table, array $values, string $sequence): string
    {
        return $this->compileInsert($table, $values) . ' RETURNING ' . $this->wrap($sequence);
    }

    /**
     * Compile an INSERT OR IGNORE statement.
     *
     * SQLite uses INSERT OR IGNORE syntax instead of ON CONFLICT DO NOTHING.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     */
    public function compileInsertOrIgnore(string $table, array $values): string
    {
        $insert = $this->compileInsert($table, $values);

        return 'INSERT OR IGNORE' . substr($insert, 6);
    }

    /**
     * Compile an UPSERT (INSERT ... ON CONFLICT ... DO UPDATE) statement.
     *
     * Available since SQLite 3.24.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     * @param list<string> $uniqueBy Columns forming the unique constraint
     * @param list<string> $update Columns to update on conflict
     */
    public function compileUpsert(string $table, array $values, array $uniqueBy, array $update): string
    {
        $insert = $this->compileInsert($table, $values);

        $uniqueWrapped = array_map(
            fn(string $column): string => $this->wrap($column),
            $uniqueBy,
        );

        $updateClauses = [];
        foreach ($update as $column) {
            $wrapped = $this->wrap($column);
            $updateClauses[] = $wrapped . ' = ' . $this->wrap('excluded') . '.' . $wrapped;
        }

        return $insert
            . ' ON CONFLICT (' . implode(', ', $uniqueWrapped) . ')'
            . ' DO UPDATE SET ' . implode(', ', $updateClauses);
    }

    /**
     * Compile a locking clause.
     *
     * SQLite uses file-level locking and does not support row-level locks.
     * Returns an empty string as a no-op.
     *
     * @param string|bool $lock The lock type (ignored for SQLite)
     */
    protected function compileLock(string|bool $lock): string
    {
        return '';
    }

    /**
     * Compile a TRUNCATE TABLE statement.
     *
     * SQLite does not support TRUNCATE. Uses DELETE FROM instead.
     * The autoincrement sequence is reset separately via sqlite_sequence.
     *
     * @param string $table The table name
     */
    public function compileTruncate(string $table): string
    {
        return 'DELETE FROM ' . $this->wrapTable($table);
    }

    /**
     * Compile a date-based WHERE clause.
     *
     * SQLite uses strftime() for date extraction.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileDateWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var string $operator */
        $operator = $where['operator'];
        /** @var string $value */
        $value = $where['value'];
        /** @var string $dateType */
        $dateType = $where['dateType'] ?? 'date';

        $wrappedColumn = $this->wrap($column);

        return match ($dateType) {
            'date' => "strftime('%Y-%m-%d', " . $wrappedColumn . ') ' . $operator . ' ' . $value,
            'time' => "strftime('%H:%M:%S', " . $wrappedColumn . ') ' . $operator . ' ' . $value,
            'year' => "CAST(strftime('%Y', " . $wrappedColumn . ') AS INTEGER) ' . $operator . ' ' . $value,
            'month' => "CAST(strftime('%m', " . $wrappedColumn . ') AS INTEGER) ' . $operator . ' ' . $value,
            'day' => "CAST(strftime('%d', " . $wrappedColumn . ') AS INTEGER) ' . $operator . ' ' . $value,
            default => throw new \InvalidArgumentException('Unsupported date type: ' . $dateType),
        };
    }

    /**
     * Compile a JSON_CONTAINS WHERE clause.
     *
     * SQLite uses json_each() with an EXISTS subquery for containment checks.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileJsonContainsWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var string $value */
        $value = $where['value'];
        /** @var bool $not */
        $not = $where['not'] ?? false;

        $sql = 'EXISTS (SELECT 1 FROM json_each(' . $this->wrap($column) . ') WHERE ' . $this->wrap('json_each') . '.' . $this->wrap('value') . ' IS ' . $value . ')';

        return $not ? 'NOT ' . $sql : $sql;
    }

    /**
     * Compile a JSON_LENGTH WHERE clause.
     *
     * SQLite uses json_array_length().
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileJsonLengthWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var string $operator */
        $operator = $where['operator'];
        /** @var string $value */
        $value = $where['value'];

        return 'json_array_length(' . $this->wrap($column) . ') ' . $operator . ' ' . $value;
    }

    /**
     * Compile a FULLTEXT search WHERE clause.
     *
     * SQLite full-text search requires FTS5 virtual tables and cannot be
     * applied to regular columns. This compiles a LIKE-based fallback.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileFullTextWhere(array $where): string
    {
        /** @var list<string> $columns */
        $columns = $where['columns'];
        /** @var string $value */
        $value = $where['value'];

        $conditions = array_map(
            fn(string $column): string => $this->wrap($column) . ' LIKE ' . $value,
            $columns,
        );

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * Compile a LIKE WHERE clause.
     *
     * SQLite LIKE is case-insensitive by default for ASCII characters.
     * Case sensitivity is not configurable via SQL syntax alone.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileLikeWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var string $value */
        $value = $where['value'];
        /** @var bool $not */
        $not = $where['not'] ?? false;

        $keyword = $not ? 'NOT LIKE' : 'LIKE';

        return $this->wrap($column) . ' ' . $keyword . ' ' . $value;
    }

    /**
     * Compile a random ordering expression.
     *
     * SQLite uses RANDOM() instead of RAND().
     */
    public function compileRandomOrder(): string
    {
        return 'RANDOM()';
    }
}

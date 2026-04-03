<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query\Grammar;

/**
 * PostgreSQL query grammar.
 *
 * Overrides the base Grammar for PostgreSQL-specific SQL dialect:
 * double-quote identifier quoting, RETURNING clause, jsonb operators,
 * full-text search via tsvector/tsquery, and ILIKE support.
 */
final class PostgresGrammar extends Grammar
{
    /**
     * Wrap a single column or identifier segment in double quotes.
     *
     * PostgreSQL uses double-quote quoting instead of backticks.
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
     * PostgreSQL uses the RETURNING clause to retrieve the inserted ID.
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
     * PostgreSQL uses ON CONFLICT DO NOTHING.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     */
    public function compileInsertOrIgnore(string $table, array $values): string
    {
        return $this->compileInsert($table, $values) . ' ON CONFLICT DO NOTHING';
    }

    /**
     * Compile an UPSERT (INSERT ... ON CONFLICT ... DO UPDATE) statement.
     *
     * PostgreSQL uses ON CONFLICT (columns) DO UPDATE SET syntax.
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
     * PostgreSQL supports FOR UPDATE and FOR SHARE.
     *
     * @param string|bool $lock The lock type (true for exclusive, false for shared, string for custom)
     */
    protected function compileLock(string|bool $lock): string
    {
        if (is_string($lock)) {
            return $lock;
        }

        return $lock ? 'FOR UPDATE' : 'FOR SHARE';
    }

    /**
     * Compile a TRUNCATE TABLE statement.
     *
     * PostgreSQL supports RESTART IDENTITY CASCADE to reset sequences
     * and handle foreign key constraints.
     *
     * @param string $table The table name
     */
    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrapTable($table) . ' RESTART IDENTITY CASCADE';
    }

    /**
     * Compile a date-based WHERE clause.
     *
     * PostgreSQL uses ::date casting and EXTRACT() for date parts.
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
            'date' => $wrappedColumn . '::date ' . $operator . ' ' . $value,
            'time' => $wrappedColumn . '::time ' . $operator . ' ' . $value,
            'year' => 'EXTRACT(YEAR FROM ' . $wrappedColumn . ') ' . $operator . ' ' . $value,
            'month' => 'EXTRACT(MONTH FROM ' . $wrappedColumn . ') ' . $operator . ' ' . $value,
            'day' => 'EXTRACT(DAY FROM ' . $wrappedColumn . ') ' . $operator . ' ' . $value,
            default => throw new \InvalidArgumentException('Unsupported date type: ' . $dateType),
        };
    }

    /**
     * Compile a JSON_CONTAINS WHERE clause.
     *
     * PostgreSQL uses the @> jsonb containment operator.
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

        $sql = $this->wrap($column) . '::jsonb @> ' . $value;

        return $not ? 'NOT (' . $sql . ')' : $sql;
    }

    /**
     * Compile a JSON_LENGTH WHERE clause.
     *
     * PostgreSQL uses jsonb_array_length().
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

        return 'jsonb_array_length(' . $this->wrap($column) . '::jsonb) ' . $operator . ' ' . $value;
    }

    /**
     * Compile a FULLTEXT search WHERE clause.
     *
     * PostgreSQL uses tsvector/tsquery with the @@ match operator.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileFullTextWhere(array $where): string
    {
        /** @var list<string> $columns */
        $columns = $where['columns'];
        /** @var string $value */
        $value = $where['value'];

        $columnExpressions = array_map(
            fn(string $column): string => 'to_tsvector(' . $this->wrap($column) . ')',
            $columns,
        );

        return implode(' || ', $columnExpressions) . ' @@ plainto_tsquery(' . $value . ')';
    }

    /**
     * Compile a LIKE WHERE clause.
     *
     * PostgreSQL uses ILIKE for case-insensitive matching.
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
        /** @var bool $caseSensitive */
        $caseSensitive = $where['caseSensitive'] ?? true;

        $keyword = $caseSensitive ? 'LIKE' : 'ILIKE';

        if ($not) {
            $keyword = 'NOT ' . $keyword;
        }

        return $this->wrap($column) . ' ' . $keyword . ' ' . $value;
    }

    /**
     * Compile a random ordering expression.
     *
     * PostgreSQL uses RANDOM() instead of RAND().
     */
    public function compileRandomOrder(): string
    {
        return 'RANDOM()';
    }
}

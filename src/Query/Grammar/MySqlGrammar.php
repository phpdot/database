<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query\Grammar;

/**
 * MySQL-specific SQL grammar.
 *
 * Handles MySQL dialect syntax including INSERT IGNORE, ON DUPLICATE KEY UPDATE,
 * JSON functions, FULLTEXT search, LIKE BINARY, and locking modes.
 */
final class MySqlGrammar extends Grammar
{
    /**
     * Compile an INSERT OR IGNORE statement using MySQL's INSERT IGNORE syntax.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     */
    public function compileInsertOrIgnore(string $table, array $values): string
    {
        return str_replace('INSERT INTO', 'INSERT IGNORE INTO', $this->compileInsert($table, $values));
    }

    /**
     * Compile an UPSERT statement using MySQL's ON DUPLICATE KEY UPDATE syntax.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     * @param list<string> $uniqueBy Columns forming the unique constraint (unused in MySQL syntax)
     * @param list<string> $update Columns to update on duplicate key
     */
    public function compileUpsert(string $table, array $values, array $uniqueBy, array $update): string
    {
        $insert = $this->compileInsert($table, $values);

        $updateClauses = [];
        foreach ($update as $column) {
            $wrapped = $this->wrap($column);
            $updateClauses[] = $wrapped . ' = VALUES(' . $wrapped . ')';
        }

        return $insert . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateClauses);
    }

    /**
     * Compile a locking clause for MySQL.
     *
     * Returns "FOR UPDATE" for exclusive locks and "LOCK IN SHARE MODE" for shared locks.
     *
     * @param string|bool $lock The lock type
     */
    protected function compileLock(string|bool $lock): string
    {
        if ($lock === true) {
            return 'FOR UPDATE';
        }

        if (is_string($lock)) {
            return $lock;
        }

        return '';
    }

    /**
     * Compile a JSON_CONTAINS WHERE clause for MySQL.
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

        $sql = 'JSON_CONTAINS(' . $this->wrapJsonSelector($column) . ', ' . $value . ')';

        return $not ? 'NOT ' . $sql : $sql;
    }

    /**
     * Compile a JSON_LENGTH WHERE clause for MySQL.
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

        return 'JSON_LENGTH(' . $this->wrapJsonSelector($column) . ') ' . $operator . ' ' . $value;
    }

    /**
     * Compile a FULLTEXT search WHERE clause using MySQL's MATCH ... AGAINST syntax.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileFullTextWhere(array $where): string
    {
        /** @var list<string> $columns */
        $columns = $where['columns'];
        /** @var string $value */
        $value = $where['value'];
        /** @var array<string, mixed> $options */
        $options = $where['options'] ?? [];

        $mode = '';
        if (isset($options['mode']) && is_string($options['mode'])) {
            $mode = match ($options['mode']) {
                'boolean' => ' IN BOOLEAN MODE',
                'natural' => ' IN NATURAL LANGUAGE MODE',
                'expansion' => ' IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION',
                default => '',
            };
        }

        return 'MATCH(' . $this->columnize($columns) . ') AGAINST(' . $value . $mode . ')';
    }

    /**
     * Compile a date-based WHERE clause using MySQL date functions.
     *
     * Supports DATE(), MONTH(), YEAR(), DAY(), and TIME() extraction.
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

        $function = strtoupper($dateType);

        return $function . '(' . $this->wrap($column) . ') ' . $operator . ' ' . $value;
    }

    /**
     * Compile a LIKE WHERE clause with optional case sensitivity for MySQL.
     *
     * Uses LIKE BINARY for case-sensitive matching, plain LIKE otherwise.
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
        $caseSensitive = $where['caseSensitive'] ?? false;

        $keyword = $not ? 'NOT LIKE' : 'LIKE';

        if ($caseSensitive) {
            $keyword .= ' BINARY';
        }

        return $this->wrap($column) . ' ' . $keyword . ' ' . $value;
    }

    /**
     * Compile a random ordering expression for MySQL.
     */
    public function compileRandomOrder(): string
    {
        return 'RAND()';
    }

    /**
     * Compile a TRUNCATE TABLE statement for MySQL.
     *
     * @param string $table The table name
     */
    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrapTable($table);
    }


    /**
     * Wrap a JSON selector path for MySQL (e.g. "column->path" becomes `column`->'$.path').
     *
     * @param string $column The column with optional JSON path (using -> notation)
     */
    private function wrapJsonSelector(string $column): string
    {
        $arrowPos = strpos($column, '->');
        if ($arrowPos === false) {
            return $this->wrap($column);
        }

        $field = substr($column, 0, $arrowPos);
        $path = substr($column, $arrowPos + 2);

        $jsonPath = str_replace('->', '.', $path);

        return $this->wrap($field) . '->\'$.' . $jsonPath . '\'';
    }
}

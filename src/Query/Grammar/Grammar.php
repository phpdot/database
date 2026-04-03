<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query\Grammar;

use InvalidArgumentException;
use PHPdot\Database\Query\Expression;

/**
 * Abstract base class for SQL compilation.
 *
 * Receives typed arrays describing query components from the Builder
 * and compiles them into SQL strings. Each database driver extends
 * this class to handle dialect-specific syntax.
 */
abstract class Grammar
{
    protected string $tablePrefix = '';

    /**
     * Set the table prefix for all compiled queries.
     *
     * @param string $prefix The table name prefix
     */
    public function setTablePrefix(string $prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    /**
     * Get the current table prefix.
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }


    /**
     * Compile a SELECT query from its component arrays.
     *
     * @param array{
     *     columns?: list<string|Expression>,
     *     from?: string,
     *     fromRaw?: string|null,
     *     fromSub?: array{query: string, alias: string}|null,
     *     distinct?: bool,
     *     joins?: list<array<string, mixed>>,
     *     wheres?: list<array<string, mixed>>,
     *     groups?: list<string>,
     *     havings?: list<array<string, mixed>>,
     *     orders?: list<array<string, mixed>>,
     *     limit?: int|null,
     *     offset?: int|null,
     *     unions?: list<array<string, mixed>>,
     *     lock?: string|bool,
     *     ctes?: list<array<string, mixed>>,
     * } $query The query components
     */
    public function compileSelect(array $query): string
    {
        $sql = [];

        /** @var list<array<string, mixed>> $ctes */
        $ctes = $query['ctes'] ?? [];
        if ($ctes !== []) {
            $sql[] = $this->compileCtes($ctes);
        }

        $sql[] = $this->compileDistinct($query['distinct'] ?? false);

        /** @var list<string|Expression> $columns */
        $columns = $query['columns'] ?? ['*'];
        $sql[] = $this->compileColumns($columns);

        /** @var array{query: string, alias: string}|null $fromSub */
        $fromSub = $query['fromSub'] ?? null;
        /** @var string|null $fromRaw */
        $fromRaw = $query['fromRaw'] ?? null;

        if ($fromSub !== null) {
            $sql[] = 'FROM (' . $fromSub['query'] . ') AS ' . $this->wrapTable($fromSub['alias']);
        } elseif ($fromRaw !== null) {
            $sql[] = 'FROM ' . $fromRaw;
        } else {
            $from = $query['from'] ?? '';
            if ($from !== '') {
                $sql[] = $this->compileFrom($from);
            }
        }

        /** @var list<array<string, mixed>> $joins */
        $joins = $query['joins'] ?? [];
        if ($joins !== []) {
            $sql[] = $this->compileJoins($joins);
        }

        /** @var list<array<string, mixed>> $wheres */
        $wheres = $query['wheres'] ?? [];
        if ($wheres !== []) {
            $sql[] = $this->compileWheres($wheres);
        }

        /** @var list<string> $groups */
        $groups = $query['groups'] ?? [];
        if ($groups !== []) {
            $sql[] = $this->compileGroups($groups);
        }

        /** @var list<array<string, mixed>> $havings */
        $havings = $query['havings'] ?? [];
        if ($havings !== []) {
            $sql[] = $this->compileHavings($havings);
        }

        /** @var list<array<string, mixed>> $orders */
        $orders = $query['orders'] ?? [];
        if ($orders !== []) {
            $sql[] = $this->compileOrders($orders);
        }

        /** @var int|null $limit */
        $limit = $query['limit'] ?? null;
        if ($limit !== null) {
            $sql[] = $this->compileLimit($limit);
        }

        /** @var int|null $offset */
        $offset = $query['offset'] ?? null;
        if ($offset !== null) {
            $sql[] = $this->compileOffset($offset);
        }

        /** @var list<array<string, mixed>> $unions */
        $unions = $query['unions'] ?? [];
        if ($unions !== []) {
            $sql[] = $this->compileUnions($unions);
        }

        /** @var string|bool $lock */
        $lock = $query['lock'] ?? false;
        if ($lock !== false) {
            $sql[] = $this->compileLock($lock);
        }

        return implode(' ', array_filter($sql, static fn(string $s): bool => $s !== ''));
    }

    /**
     * Compile an INSERT statement.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     */
    public function compileInsert(string $table, array $values): string
    {
        $columns = $this->columnize(array_keys($values));
        $parameters = $this->parameterize(array_values($values));

        return 'INSERT INTO ' . $this->wrapTable($table) . ' (' . $columns . ') VALUES (' . $parameters . ')';
    }

    /**
     * Compile an INSERT statement that returns the auto-increment ID.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     * @param string $sequence The name of the sequence column
     */
    public function compileInsertGetId(string $table, array $values, string $sequence): string
    {
        return $this->compileInsert($table, $values);
    }

    /**
     * Compile an INSERT statement for multiple rows.
     *
     * @param string $table The table name
     * @param list<string> $columns Column names
     * @param list<list<mixed>> $rows List of value rows
     */
    public function compileInsertBatch(string $table, array $columns, array $rows): string
    {
        $columnList = $this->columnize($columns);

        $rowValues = [];
        foreach ($rows as $row) {
            $rowValues[] = '(' . $this->parameterize($row) . ')';
        }

        return 'INSERT INTO ' . $this->wrapTable($table) . ' (' . $columnList . ') VALUES ' . implode(', ', $rowValues);
    }

    /**
     * Compile an INSERT OR IGNORE statement.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     */
    public function compileInsertOrIgnore(string $table, array $values): string
    {
        return $this->compileInsert($table, $values);
    }

    /**
     * Compile an INSERT INTO ... SELECT statement.
     *
     * @param string $table The table name
     * @param list<string> $columns Column names
     * @param string $sql The SELECT SQL to insert from
     */
    public function compileInsertUsing(string $table, array $columns, string $sql): string
    {
        $columnList = $this->columnize($columns);

        return 'INSERT INTO ' . $this->wrapTable($table) . ' (' . $columnList . ') ' . $sql;
    }

    /**
     * Compile an UPDATE statement.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to update
     * @param list<array<string, mixed>> $wheres Where clause arrays
     * @param list<mixed> $bindings Query bindings (unused in base, available for overrides)
     */
    public function compileUpdate(string $table, array $values, array $wheres, array $bindings): string
    {
        $sets = [];
        foreach ($values as $key => $value) {
            $sets[] = $this->wrap($key) . ' = ' . ($value instanceof Expression ? $this->getValue($value) : '?');
        }

        $sql = 'UPDATE ' . $this->wrapTable($table) . ' SET ' . implode(', ', $sets);

        if ($wheres !== []) {
            $sql .= ' ' . $this->compileWheres($wheres);
        }

        return $sql;
    }

    /**
     * Compile an UPSERT (INSERT ... ON CONFLICT UPDATE) statement.
     *
     * @param string $table The table name
     * @param array<string, mixed> $values Column-value pairs to insert
     * @param list<string> $uniqueBy Columns forming the unique constraint
     * @param list<string> $update Columns to update on conflict
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
     * Compile a DELETE statement.
     *
     * @param string $table The table name
     * @param list<array<string, mixed>> $wheres Where clause arrays
     * @param list<mixed> $bindings Query bindings (unused in base, available for overrides)
     */
    public function compileDelete(string $table, array $wheres, array $bindings): string
    {
        $sql = 'DELETE FROM ' . $this->wrapTable($table);

        if ($wheres !== []) {
            $sql .= ' ' . $this->compileWheres($wheres);
        }

        return $sql;
    }

    /**
     * Compile a TRUNCATE TABLE statement.
     *
     * @param string $table The table name
     */
    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrapTable($table);
    }

    /**
     * Compile an EXISTS wrapper around an existing SQL query.
     *
     * @param string $sql The inner SELECT query
     */
    public function compileExists(string $sql): string
    {
        return 'SELECT EXISTS(' . $sql . ') AS ' . $this->wrap('exists');
    }


    /**
     * Compile the SELECT DISTINCT keyword.
     *
     * @param bool $distinct Whether the query is distinct
     */
    protected function compileDistinct(bool $distinct): string
    {
        return $distinct ? 'SELECT DISTINCT' : 'SELECT';
    }

    /**
     * Compile the column list for a SELECT query.
     *
     * @param list<string|Expression> $columns Column names or expressions
     */
    protected function compileColumns(array $columns): string
    {
        $compiled = [];
        foreach ($columns as $column) {
            $compiled[] = $column instanceof Expression ? $this->getValue($column) : $this->wrap($column);
        }

        return implode(', ', $compiled);
    }

    /**
     * Compile the FROM clause.
     *
     * @param string $table The table name
     */
    protected function compileFrom(string $table): string
    {
        return 'FROM ' . $this->wrapTable($table);
    }

    /**
     * Compile WHERE clauses from an array of where descriptions.
     *
     * @param list<array<string, mixed>> $wheres Where clause arrays
     */
    public function compileWheres(array $wheres): string
    {
        if ($wheres === []) {
            return '';
        }

        $parts = [];
        foreach ($wheres as $i => $where) {
            /** @var string $type */
            $type = $where['type'];
            $sql = $this->dispatchWhereCompilation($type, $where);
            /** @var string $boolean */
            $boolean = $where['boolean'] ?? 'and';
            $parts[] = ($i === 0 ? '' : strtoupper($boolean) . ' ') . $sql;
        }

        return 'WHERE ' . implode(' ', $parts);
    }

    /**
     * Dispatch a where clause to the appropriate compile method by type.
     *
     * @param string $type The where clause type
     * @param array<string, mixed> $where The where clause definition
     */
    protected function dispatchWhereCompilation(string $type, array $where): string
    {
        return match ($type) {
            'basic' => $this->compileBasicWhere($where),
            'in' => $this->compileInWhere($where),
            'notIn' => $this->compileNotInWhere($where),
            'between' => $this->compileBetweenWhere($where),
            'null' => $this->compileNullWhere($where),
            'notNull' => $this->compileNotNullWhere($where),
            'exists' => $this->compileExistsWhere($where),
            'notExists' => $this->compileNotExistsWhere($where),
            'column' => $this->compileColumnWhere($where),
            'raw' => $this->compileRawWhere($where),
            'date' => $this->compileDateWhere($where),
            'jsonContains' => $this->compileJsonContainsWhere($where),
            'jsonLength' => $this->compileJsonLengthWhere($where),
            'like' => $this->compileLikeWhere($where),
            'fullText' => $this->compileFullTextWhere($where),
            'sub' => $this->compileSubWhere($where),
            'nested' => $this->compileNestedWhere($where),
            default => throw new InvalidArgumentException('Unknown where type: ' . $type),
        };
    }

    /**
     * Compile JOIN clauses.
     *
     * @param list<array<string, mixed>> $joins Join clause arrays
     */
    protected function compileJoins(array $joins): string
    {
        $parts = [];
        foreach ($joins as $join) {
            /** @var string $type */
            $type = $join['type'] ?? 'INNER';
            /** @var string $table */
            $table = $join['table'];
            /** @var list<array<string, mixed>> $clauses */
            $clauses = $join['clauses'] ?? [];

            $conditions = [];
            foreach ($clauses as $ci => $clause) {
                /** @var string $first */
                $first = $clause['first'];
                /** @var string $operator */
                $operator = $clause['operator'] ?? '=';
                /** @var string $second */
                $second = $clause['second'];
                /** @var string $joinBoolean */
                $joinBoolean = $clause['boolean'] ?? 'and';

                $condition = $this->wrap($first) . ' ' . $operator . ' ' . $this->wrap($second);
                $conditions[] = ($ci === 0 ? '' : strtoupper($joinBoolean) . ' ') . $condition;
            }

            $parts[] = strtoupper($type) . ' JOIN ' . $this->wrapTable($table) . ' ON ' . implode(' ', $conditions);
        }

        return implode(' ', $parts);
    }

    /**
     * Compile GROUP BY columns.
     *
     * @param list<string> $groups Column names to group by
     */
    protected function compileGroups(array $groups): string
    {
        return 'GROUP BY ' . $this->columnize($groups);
    }

    /**
     * Compile HAVING clauses.
     *
     * @param list<array<string, mixed>> $havings Having clause arrays
     */
    protected function compileHavings(array $havings): string
    {
        $parts = [];
        foreach ($havings as $i => $having) {
            /** @var string $boolean */
            $boolean = $having['boolean'] ?? 'and';
            /** @var string $havingType */
            $havingType = $having['type'] ?? 'basic';

            if ($havingType === 'raw') {
                /** @var string $rawSql */
                $rawSql = $having['sql'];
                $sql = $rawSql;
            } else {
                /** @var string $column */
                $column = $having['column'];
                /** @var string $operator */
                $operator = $having['operator'] ?? '=';
                /** @var string $value */
                $value = $having['value'] ?? '?';
                $sql = $this->wrap($column) . ' ' . $operator . ' ' . $value;
            }

            $parts[] = ($i === 0 ? '' : strtoupper($boolean) . ' ') . $sql;
        }

        return 'HAVING ' . implode(' ', $parts);
    }

    /**
     * Compile ORDER BY clauses.
     *
     * @param list<array<string, mixed>> $orders Order clause arrays with column and direction
     */
    protected function compileOrders(array $orders): string
    {
        $parts = [];
        foreach ($orders as $order) {
            if (isset($order['raw'])) {
                /** @var string $rawOrder */
                $rawOrder = $order['raw'];
                $parts[] = $rawOrder;
            } else {
                /** @var string $column */
                $column = $order['column'];
                /** @var string $direction */
                $direction = $order['direction'] ?? 'ASC';
                $parts[] = $this->wrap($column) . ' ' . strtoupper($direction);
            }
        }

        return 'ORDER BY ' . implode(', ', $parts);
    }

    /**
     * Compile a LIMIT clause.
     *
     * @param int|null $limit The maximum number of rows
     */
    protected function compileLimit(?int $limit): string
    {
        if ($limit === null) {
            return '';
        }

        return 'LIMIT ' . $limit;
    }

    /**
     * Compile an OFFSET clause.
     *
     * @param int|null $offset The number of rows to skip
     */
    protected function compileOffset(?int $offset): string
    {
        if ($offset === null) {
            return '';
        }

        return 'OFFSET ' . $offset;
    }

    /**
     * Compile UNION clauses.
     *
     * @param list<array<string, mixed>> $unions Union clause arrays
     */
    protected function compileUnions(array $unions): string
    {
        $parts = [];
        foreach ($unions as $union) {
            /** @var string $unionSql */
            $unionSql = $union['query'];
            /** @var bool $all */
            $all = $union['all'] ?? false;
            $parts[] = ($all ? 'UNION ALL' : 'UNION') . ' ' . $unionSql;
        }

        return implode(' ', $parts);
    }

    /**
     * Compile a locking clause.
     *
     * @param string|bool $lock The lock type (true for exclusive, string for custom)
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
     * Compile Common Table Expressions (CTEs).
     *
     * @param list<array<string, mixed>> $ctes CTE definitions
     */
    protected function compileCtes(array $ctes): string
    {
        $parts = [];
        $hasRecursive = false;

        foreach ($ctes as $cte) {
            /** @var string $name */
            $name = $cte['name'];
            /** @var string $cteSql */
            $cteSql = $cte['query'];
            /** @var bool $recursive */
            $recursive = $cte['recursive'] ?? false;

            if ($recursive) {
                $hasRecursive = true;
            }

            $parts[] = $this->wrap($name) . ' AS (' . $cteSql . ')';
        }

        return 'WITH ' . ($hasRecursive ? 'RECURSIVE ' : '') . implode(', ', $parts);
    }


    /**
     * Compile a basic where clause (column operator value).
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileBasicWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var string $operator */
        $operator = $where['operator'];
        $value = $where['value'];

        return $this->wrap($column) . ' ' . $operator . ' ' . ($value instanceof Expression ? $this->getValue($value) : $this->parameter($value));
    }

    /**
     * Compile a WHERE IN clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileInWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var list<mixed> $values */
        $values = $where['values'];
        /** @var bool $not */
        $not = $where['not'] ?? false;

        $keyword = $not ? 'NOT IN' : 'IN';

        return $this->wrap($column) . ' ' . $keyword . ' (' . implode(', ', array_map(
            fn(mixed $v): string => $v instanceof Expression ? $this->getValue($v) : $this->parameter($v),
            $values,
        )) . ')';
    }

    /**
     * Compile a WHERE NOT IN clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileNotInWhere(array $where): string
    {
        $where['not'] = true;

        return $this->compileInWhere($where);
    }

    /**
     * Compile a WHERE BETWEEN clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileBetweenWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var array{0: mixed, 1: mixed} $values */
        $values = $where['values'];
        /** @var bool $not */
        $not = $where['not'] ?? false;

        $keyword = $not ? 'NOT BETWEEN' : 'BETWEEN';

        return $this->wrap($column) . ' ' . $keyword . ' ' . $this->parameter($values[0]) . ' AND ' . $this->parameter($values[1]);
    }

    /**
     * Compile a WHERE IS NULL clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileNullWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var bool $not */
        $not = $where['not'] ?? false;

        return $this->wrap($column) . ($not ? ' IS NOT NULL' : ' IS NULL');
    }

    /**
     * Compile a WHERE IS NOT NULL clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileNotNullWhere(array $where): string
    {
        $where['not'] = true;

        return $this->compileNullWhere($where);
    }

    /**
     * Compile a WHERE EXISTS clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileExistsWhere(array $where): string
    {
        /** @var string $query */
        $query = $where['query'];
        /** @var bool $not */
        $not = $where['not'] ?? false;

        return ($not ? 'NOT EXISTS ' : 'EXISTS ') . $query;
    }

    /**
     * Compile a WHERE NOT EXISTS clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileNotExistsWhere(array $where): string
    {
        $where['not'] = true;

        return $this->compileExistsWhere($where);
    }

    /**
     * Compile a column-to-column WHERE clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileColumnWhere(array $where): string
    {
        /** @var string $first */
        $first = $where['first'];
        /** @var string $operator */
        $operator = $where['operator'];
        /** @var string $second */
        $second = $where['second'];

        return $this->wrap($first) . ' ' . $operator . ' ' . $this->wrap($second);
    }

    /**
     * Compile a raw WHERE clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileRawWhere(array $where): string
    {
        /** @var string $sql */
        $sql = $where['sql'];

        return $sql;
    }

    /**
     * Compile a date-based WHERE clause (DATE, MONTH, YEAR, DAY, TIME).
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
     * Compile a JSON_CONTAINS WHERE clause.
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

        $sql = 'JSON_CONTAINS(' . $this->wrap($column) . ', ' . $value . ')';

        return $not ? 'NOT ' . $sql : $sql;
    }

    /**
     * Compile a JSON_LENGTH WHERE clause.
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

        return 'JSON_LENGTH(' . $this->wrap($column) . ') ' . $operator . ' ' . $value;
    }

    /**
     * Compile a LIKE WHERE clause.
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
     * Compile a FULLTEXT search WHERE clause.
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileFullTextWhere(array $where): string
    {
        /** @var list<string> $columns */
        $columns = $where['columns'];
        /** @var string $value */
        $value = $where['value'];

        return 'MATCH(' . $this->columnize($columns) . ') AGAINST(' . $value . ')';
    }

    /**
     * Compile a sub-query WHERE clause (column operator (SELECT ...)).
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileSubWhere(array $where): string
    {
        /** @var string $column */
        $column = $where['column'];
        /** @var string $operator */
        $operator = $where['operator'];
        /** @var string $query */
        $query = $where['query'];

        return $this->wrap($column) . ' ' . $operator . ' ' . $query;
    }

    /**
     * Compile a nested WHERE clause (grouped conditions).
     *
     * @param array<string, mixed> $where The where clause definition
     */
    protected function compileNestedWhere(array $where): string
    {
        /** @var string $query */
        $query = $where['query'];

        return '(' . $query . ')';
    }


    /**
     * Wrap a table name with the table prefix and quoting.
     *
     * @param string $table The table name
     */
    public function wrapTable(string $table): string
    {
        return $this->wrap($this->tablePrefix . $table);
    }

    /**
     * Wrap a value (column, table, or alias) in keyword identifiers.
     *
     * Handles dot-separated segments (e.g. "users.name") and aliases (e.g. "name as display_name").
     *
     * @param string $value The value to wrap
     */
    public function wrap(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        $lower = strtolower($value);
        $asPos = strpos($lower, ' as ');
        if ($asPos !== false) {
            $segments = [
                substr($value, 0, $asPos),
                substr($value, $asPos + 4),
            ];

            return $this->wrap(trim($segments[0])) . ' AS ' . $this->wrapColumn(trim($segments[1]));
        }

        if (str_contains($value, '.')) {
            $parts = explode('.', $value);
            $wrapped = [];
            foreach ($parts as $i => $part) {
                if ($i === array_key_last($parts) && $part === '*') {
                    $wrapped[] = '*';
                } else {
                    $wrapped[] = $this->wrapColumn($part);
                }
            }

            return implode('.', $wrapped);
        }

        return $this->wrapColumn($value);
    }

    /**
     * Wrap a single column or identifier segment in backtick quotes.
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

    /**
     * Convert a list of column names into a comma-separated, quoted string.
     *
     * @param list<string> $columns Column names
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map(fn(string $column): string => $this->wrap($column), $columns));
    }

    /**
     * Convert a list of values into a comma-separated parameter string.
     *
     * @param list<mixed> $values The values to parameterize
     */
    public function parameterize(array $values): string
    {
        return implode(', ', array_map(fn(mixed $value): string => $this->parameter($value), $values));
    }

    /**
     * Get the appropriate parameter placeholder for a value.
     *
     * @param mixed $value The value to represent as a parameter
     */
    public function parameter(mixed $value): string
    {
        if ($value instanceof Expression) {
            return $this->getValue($value);
        }

        return '?';
    }

    /**
     * Determine if a value is a raw Expression.
     *
     * @param mixed $value The value to check
     */
    public function isExpression(mixed $value): bool
    {
        return $value instanceof Expression;
    }

    /**
     * Get the raw SQL string from an Expression.
     *
     * @param Expression $expression The expression
     */
    public function getValue(Expression $expression): string
    {
        return $expression->value;
    }

    /**
     * Compile a random ordering expression.
     */
    public function compileRandomOrder(): string
    {
        return 'RAND()';
    }
}

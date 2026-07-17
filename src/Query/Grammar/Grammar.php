<?php

declare(strict_types=1);

/**
 * Abstract base class for SQL compilation.
 *
 * Receives typed arrays describing query components from the Builder
 * and compiles them into SQL strings. Each database driver extends
 * this class to handle dialect-specific syntax.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query\Grammar;

use InvalidArgumentException;
use PHPdot\Database\Query\Expression;

abstract class Grammar
{
    protected string $tablePrefix = '';

    /**
     * Set the table prefix for all compiled queries.
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
     * Get the current table prefix.
     *
     * @return string
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
     *     groups?: list<string|Expression>,
     *     havings?: list<array<string, mixed>>,
     *     orders?: list<array<string, mixed>>,
     *     limit?: int|null,
     *     offset?: int|null,
     *     unions?: list<array<string, mixed>>,
     *     lock?: string|bool,
     *     ctes?: list<array<string, mixed>>,
     * } $query The query components
     *
     * @return string
     */
    public function compileSelect(array $query): string
    {
        $sql = [];

        /**
         * @var list<array<string, mixed>> $ctes
         */
        $ctes = $query['ctes'] ?? [];
        if ($ctes !== []) {
            $sql[] = $this->compileCtes($ctes);
        }

        $sql[] = $this->compileDistinct($query['distinct'] ?? false);

        /**
         * @var list<string|Expression> $columns
         */
        $columns = $query['columns'] ?? ['*'];
        $sql[] = $this->compileColumns($columns);

        /**
         * @var array{query: string, alias: string}|null $fromSub
         */
        $fromSub = $query['fromSub'] ?? null;
        /**
         * @var string|null $fromRaw
         */
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

        /**
         * @var list<array<string, mixed>> $joins
         */
        $joins = $query['joins'] ?? [];
        if ($joins !== []) {
            $sql[] = $this->compileJoins($joins);
        }

        /**
         * @var list<array<string, mixed>> $wheres
         */
        $wheres = $query['wheres'] ?? [];
        if ($wheres !== []) {
            $sql[] = $this->compileWheres($wheres);
        }

        /**
         * @var list<string|Expression> $groups
         */
        $groups = $query['groups'] ?? [];
        if ($groups !== []) {
            $sql[] = $this->compileGroups($groups);
        }

        /**
         * @var list<array<string, mixed>> $havings
         */
        $havings = $query['havings'] ?? [];
        if ($havings !== []) {
            $sql[] = $this->compileHavings($havings);
        }

        /**
         * @var list<array<string, mixed>> $unions
         */
        $unions = $query['unions'] ?? [];
        if ($unions !== []) {
            $sql[] = $this->compileUnions($unions);
        }

        /**
         * @var list<array<string, mixed>> $orders
         */
        $orders = $query['orders'] ?? [];
        if ($orders !== []) {
            $sql[] = $this->compileOrders($orders);
        }

        /**
         * @var int|null $limit
         */
        $limit = $query['limit'] ?? null;
        if ($limit !== null) {
            $sql[] = $this->compileLimit($limit);
        }

        /**
         * @var int|null $offset
         */
        $offset = $query['offset'] ?? null;
        if ($offset !== null) {
            $sql[] = $this->compileOffset($offset, $limit);
        }

        /**
         * @var string|bool $lock
         */
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return string
     */
    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrapTable($table);
    }

    /**
     * Compile the statement(s) required to truncate a table.
     *
     * Most engines truncate (and reset any auto-increment) in one statement;
     * SQLite needs a second statement to reset its sequence, so this returns
     * a list.
     *
     * @param string $table The table name
     *
     * @return list<string>
     */
    public function compileTruncateStatements(string $table): array
    {
        return [$this->compileTruncate($table)];
    }

    /**
     * Compile an EXISTS wrapper around an existing SQL query.
     *
     * @param string $sql The inner SELECT query
     *
     * @return string
     */
    public function compileExists(string $sql): string
    {
        return 'SELECT EXISTS(' . $sql . ') AS ' . $this->wrap('exists');
    }

    /**
     * Compile the SELECT DISTINCT keyword.
     *
     * @param bool $distinct Whether the query is distinct
     *
     * @return string
     */
    protected function compileDistinct(bool $distinct): string
    {
        return $distinct ? 'SELECT DISTINCT' : 'SELECT';
    }

    /**
     * Compile the column list for a SELECT query.
     *
     * @param list<string|Expression> $columns Column names or expressions
     *
     * @return string
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
     *
     * @return string
     */
    protected function compileFrom(string $table): string
    {
        return 'FROM ' . $this->wrapTable($table);
    }

    /**
     * Compile WHERE clauses from an array of where descriptions.
     *
     * @param list<array<string, mixed>> $wheres Where clause arrays
     *
     * @return string
     */
    public function compileWheres(array $wheres): string
    {
        if ($wheres === []) {
            return '';
        }

        $parts = [];
        foreach ($wheres as $i => $where) {
            /**
             * @var string $type
             */
            $type = $where['type'];
            $sql = $this->dispatchWhereCompilation($type, $where);
            /**
             * @var string $boolean
             */
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
     *
     * @return string
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
     *
     * @return string
     */
    protected function compileJoins(array $joins): string
    {
        $parts = [];
        foreach ($joins as $join) {
            /**
             * @var string $type
             */
            $type = $join['type'] ?? 'INNER';
            /**
             * @var string $table
             */
            $table = $join['table'];
            /**
             * @var string|null $subQuery
             */
            $subQuery = $join['subQuery'] ?? null;
            /**
             * @var list<array<string, mixed>> $clauses
             */
            $clauses = $join['clauses'] ?? [];

            $conditions = [];
            foreach ($clauses as $ci => $clause) {
                /**
                 * @var string $joinBoolean
                 */
                $joinBoolean = $clause['boolean'] ?? 'and';
                $conditions[] = ($ci === 0 ? '' : strtoupper($joinBoolean) . ' ') . $this->compileJoinClause($clause);
            }

            if ($subQuery !== null && $subQuery !== '') {
                /**
                 * @var string $alias
                 */
                $alias = $join['alias'] ?? '';
                $target = '(' . $subQuery . ') AS ' . $this->wrapTable($alias);
            } else {
                $target = $this->wrapTable($table);
            }

            $joinSql = strtoupper($type) . ' JOIN ' . $target;

            if ($conditions !== []) {
                $joinSql .= ' ON ' . implode(' ', $conditions);
            }

            $parts[] = $joinSql;
        }

        return implode(' ', $parts);
    }

    /**
     * Compile a single JOIN condition.
     *
     * Handles ON column comparisons plus the value/null/in/raw predicates that
     * JoinClause's where* helpers can add — not just column-to-column ONs.
     *
     * @param array<string, mixed> $clause The join condition definition
     *
     * @return string
     */
    protected function compileJoinClause(array $clause): string
    {
        $type = $this->joinString($clause, 'type', 'on');

        return match ($type) {
            'where' => $this->wrap($this->joinString($clause, 'column'))
                . ' ' . $this->joinString($clause, 'operator', '=')
                . ' ' . $this->joinString($clause, 'value', '?'),
            'null' => $this->wrap($this->joinString($clause, 'column')) . ' IS NULL',
            'notNull' => $this->wrap($this->joinString($clause, 'column')) . ' IS NOT NULL',
            'in' => $this->compileJoinIn($clause, false),
            'notIn' => $this->compileJoinIn($clause, true),
            'raw' => $this->joinString($clause, 'sql'),
            default => $this->wrap($this->joinString($clause, 'first'))
                . ' ' . $this->joinString($clause, 'operator', '=')
                . ' ' . $this->wrap($this->joinString($clause, 'second')),
        };
    }

    /**
     * Compile an IN / NOT IN join condition, guarding the empty-list case.
     *
     * @param array<string, mixed> $clause The join condition definition
     * @param bool $not Whether to negate (NOT IN)
     *
     * @return string
     */
    private function compileJoinIn(array $clause, bool $not): string
    {
        /**
         * @var list<string> $values
         */
        $values = $clause['values'] ?? [];

        if ($values === []) {
            return $not ? '1 = 1' : '0 = 1';
        }

        return $this->wrap($this->joinString($clause, 'column'))
            . ' ' . ($not ? 'NOT IN' : 'IN') . ' (' . implode(', ', $values) . ')';
    }

    /**
     * Read a string value from a join clause definition.
     *
     * @param array<string, mixed> $clause The join condition definition
     * @param string $key The key to read
     * @param string $default Fallback when the key is missing or non-string
     *
     * @return string
     */
    private function joinString(array $clause, string $key, string $default = ''): string
    {
        $value = $clause[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Compile GROUP BY columns.
     *
     * @param list<string|Expression> $groups Column names or raw expressions to group by
     *
     * @return string
     */
    protected function compileGroups(array $groups): string
    {
        $compiled = array_map(
            fn(string|Expression $group): string => $group instanceof Expression
                ? $this->getValue($group)
                : $this->wrap($group),
            $groups,
        );

        return 'GROUP BY ' . implode(', ', $compiled);
    }

    /**
     * Compile HAVING clauses.
     *
     * @param list<array<string, mixed>> $havings Having clause arrays
     *
     * @return string
     */
    protected function compileHavings(array $havings): string
    {
        $parts = [];
        foreach ($havings as $i => $having) {
            /**
             * @var string $boolean
             */
            $boolean = $having['boolean'] ?? 'and';
            /**
             * @var string $havingType
             */
            $havingType = $having['type'] ?? 'basic';

            if ($havingType === 'raw') {
                /**
                 * @var string $rawSql
                 */
                $rawSql = $having['sql'];
                $sql = $rawSql;
            } else {
                /**
                 * @var string $column
                 */
                $column = $having['column'];
                /**
                 * @var string $operator
                 */
                $operator = $having['operator'] ?? '=';
                /**
                 * @var string $value
                 */
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
     *
     * @return string
     */
    protected function compileOrders(array $orders): string
    {
        $parts = [];
        foreach ($orders as $order) {
            if (isset($order['raw'])) {
                /**
                 * @var string $rawOrder
                 */
                $rawOrder = $order['raw'];
                $parts[] = $rawOrder;
            } else {
                /**
                 * @var string|Expression $column
                 */
                $column = $order['column'];
                /**
                 * @var string $direction
                 */
                $direction = $order['direction'] ?? 'ASC';
                $rendered = $column instanceof Expression ? $this->getValue($column) : $this->wrap($column);
                $parts[] = $rendered . ' ' . strtoupper($direction);
            }
        }

        return 'ORDER BY ' . implode(', ', $parts);
    }

    /**
     * Compile a LIMIT clause.
     *
     * @param int|null $limit The maximum number of rows
     *
     * @return string
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
     * @param int|null $limit The active LIMIT, if any (drivers that reject a bare OFFSET need it)
     *
     * @return string
     */
    protected function compileOffset(?int $offset, ?int $limit = null): string
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
     *
     * @return string
     */
    protected function compileUnions(array $unions): string
    {
        $parts = [];
        foreach ($unions as $union) {
            /**
             * @var string $unionSql
             */
            $unionSql = $union['query'];
            /**
             * @var bool $all
             */
            $all = $union['all'] ?? false;
            $parts[] = ($all ? 'UNION ALL' : 'UNION') . ' ' . $unionSql;
        }

        return implode(' ', $parts);
    }

    /**
     * Compile a locking clause.
     *
     * @param string|bool $lock The lock type (true for exclusive, string for custom)
     *
     * @return string
     */
    protected function compileLock(string|bool $lock): string
    {
        if ($lock === true) {
            return 'FOR UPDATE';
        }

        if ($lock === 'shared') {
            return 'FOR SHARE';
        }

        return '';
    }

    /**
     * Compile Common Table Expressions (CTEs).
     *
     * @param list<array<string, mixed>> $ctes CTE definitions
     *
     * @return string
     */
    protected function compileCtes(array $ctes): string
    {
        $parts = [];
        $hasRecursive = false;

        foreach ($ctes as $cte) {
            /**
             * @var string $name
             */
            $name = $cte['name'];
            /**
             * @var string $cteSql
             */
            $cteSql = $cte['query'];
            /**
             * @var bool $recursive
             */
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
     *
     * @return string
     */
    protected function compileBasicWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var string $operator
         */
        $operator = $where['operator'];
        $value = $where['value'];

        return $this->wrap($column) . ' ' . $operator . ' ' . ($value instanceof Expression ? $this->getValue($value) : $this->parameter($value));
    }

    /**
     * Compile a WHERE IN clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileInWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var list<mixed> $values
         */
        $values = $where['values'];
        /**
         * @var bool $not
         */
        $not = $where['not'] ?? false;

        if ($values === []) {
            return $not ? '1 = 1' : '0 = 1';
        }

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
     *
     * @return string
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
     *
     * @return string
     */
    protected function compileBetweenWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var array{0: mixed, 1: mixed} $values
         */
        $values = $where['values'];
        /**
         * @var bool $not
         */
        $not = $where['not'] ?? false;

        $keyword = $not ? 'NOT BETWEEN' : 'BETWEEN';

        return $this->wrap($column) . ' ' . $keyword . ' ' . $this->parameter($values[0]) . ' AND ' . $this->parameter($values[1]);
    }

    /**
     * Compile a WHERE IS NULL clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileNullWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var bool $not
         */
        $not = $where['not'] ?? false;

        return $this->wrap($column) . ($not ? ' IS NOT NULL' : ' IS NULL');
    }

    /**
     * Compile a WHERE IS NOT NULL clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
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
     *
     * @return string
     */
    protected function compileExistsWhere(array $where): string
    {
        /**
         * @var string $query
         */
        $query = $where['query'];
        /**
         * @var bool $not
         */
        $not = $where['not'] ?? false;

        return ($not ? 'NOT EXISTS ' : 'EXISTS ') . $query;
    }

    /**
     * Compile a WHERE NOT EXISTS clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
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
     *
     * @return string
     */
    protected function compileColumnWhere(array $where): string
    {
        /**
         * @var string $first
         */
        $first = $where['first'];
        /**
         * @var string $operator
         */
        $operator = $where['operator'];
        /**
         * @var string $second
         */
        $second = $where['second'];

        return $this->wrap($first) . ' ' . $operator . ' ' . $this->wrap($second);
    }

    /**
     * Compile a raw WHERE clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileRawWhere(array $where): string
    {
        /**
         * @var string $sql
         */
        $sql = $where['sql'];

        return $sql;
    }

    /**
     * Compile a date-based WHERE clause (DATE, MONTH, YEAR, DAY, TIME).
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileDateWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var string $operator
         */
        $operator = $where['operator'];
        /**
         * @var string $value
         */
        $value = $where['value'];
        /**
         * @var string $dateType
         */
        $dateType = $where['dateType'] ?? 'date';

        $function = strtoupper($dateType);

        return $function . '(' . $this->wrap($column) . ') ' . $operator . ' ' . $value;
    }

    /**
     * Encode a whereJsonContains value for this driver's containment operator.
     *
     * MySQL (JSON_CONTAINS) and PostgreSQL (@>) compare against a JSON document,
     * so the PHP value is JSON-encoded. SQLite overrides this to compare the raw
     * scalar, so a single call works identically across drivers.
     *
     * @param mixed $value The PHP value to test for
     *
     * @return mixed
     */
    public function prepareJsonContainsBinding(mixed $value): mixed
    {
        $encoded = json_encode($value);

        return $encoded === false ? $value : $encoded;
    }

    /**
     * Compile a JSON_CONTAINS WHERE clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileJsonContainsWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var string $value
         */
        $value = $where['value'];
        /**
         * @var bool $not
         */
        $not = $where['not'] ?? false;

        $sql = 'JSON_CONTAINS(' . $this->wrap($column) . ', ' . $value . ')';

        return $not ? 'NOT ' . $sql : $sql;
    }

    /**
     * Compile a JSON_LENGTH WHERE clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileJsonLengthWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var string $operator
         */
        $operator = $where['operator'];
        /**
         * @var string $value
         */
        $value = $where['value'];

        return 'JSON_LENGTH(' . $this->wrap($column) . ') ' . $operator . ' ' . $value;
    }

    /**
     * Compile a LIKE WHERE clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileLikeWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var string $value
         */
        $value = $where['value'];
        /**
         * @var bool $not
         */
        $not = $where['not'] ?? false;

        $keyword = $not ? 'NOT LIKE' : 'LIKE';

        return $this->wrap($column) . ' ' . $keyword . ' ' . $value;
    }

    /**
     * Compile a FULLTEXT search WHERE clause.
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileFullTextWhere(array $where): string
    {
        /**
         * @var list<string> $columns
         */
        $columns = $where['columns'];
        /**
         * @var string $value
         */
        $value = $where['value'];

        return 'MATCH(' . $this->columnize($columns) . ') AGAINST(' . $value . ')';
    }

    /**
     * Compile a sub-query WHERE clause (column operator (SELECT ...)).
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileSubWhere(array $where): string
    {
        /**
         * @var string $column
         */
        $column = $where['column'];
        /**
         * @var string $operator
         */
        $operator = $where['operator'];
        /**
         * @var string $query
         */
        $query = $where['query'];

        return $this->wrap($column) . ' ' . $operator . ' ' . $query;
    }

    /**
     * Compile a nested WHERE clause (grouped conditions).
     *
     * @param array<string, mixed> $where The where clause definition
     *
     * @return string
     */
    protected function compileNestedWhere(array $where): string
    {
        /**
         * @var string $query
         */
        $query = $where['query'];
        /**
         * @var bool $not
         */
        $not = $where['not'] ?? false;

        return ($not ? 'NOT ' : '') . '(' . $query . ')';
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
        return $this->wrap($this->tablePrefix . $table);
    }

    /**
     * Wrap a value (column, table, or alias) in keyword identifiers.
     *
     * Handles dot-separated segments (e.g. "users.name") and aliases (e.g. "name as display_name").
     *
     * @param string $value The value to wrap
     *
     * @return string
     */
    public function wrap(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        if (str_contains($value, '->')) {
            return $this->wrapJsonSelector($value);
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
     * Split a "column->key->key2" JSON selector into its column and validated
     * path segments. Segments must be bare keys or [index] array accessors.
     *
     * @param string $column The column with a -> JSON path
     *
     * @throws InvalidArgumentException When a path segment is malformed
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function parseJsonSelector(string $column): array
    {
        $arrowPos = strpos($column, '->');

        if ($arrowPos === false) {
            return [$column, []];
        }

        $field = substr($column, 0, $arrowPos);
        $path = substr($column, $arrowPos + 2);
        $segments = explode('->', $path);

        foreach ($segments as $segment) {
            if (preg_match('/^([A-Za-z0-9_]+|\[[0-9]+\])(\[[0-9]+\])*$/', $segment) !== 1) {
                throw new InvalidArgumentException('Invalid JSON path segment: ' . $segment);
            }
        }

        return [$field, $segments];
    }

    /**
     * Wrap a JSON path selector ("column->key"). The default uses the standard
     * json_extract() form (SQLite); MySQL and PostgreSQL override it with their
     * native operators.
     *
     * @param string $column The column with a -> JSON path
     *
     * @return string
     */
    protected function wrapJsonSelector(string $column): string
    {
        [$field, $segments] = $this->parseJsonSelector($column);

        if ($segments === []) {
            return $this->wrap($field);
        }

        return 'json_extract(' . $this->wrap($field) . ", '$." . implode('.', $segments) . "')";
    }

    /**
     * Wrap a single column or identifier segment in backtick quotes.
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
     * Convert a list of column names into a comma-separated, quoted string.
     *
     * @param list<string> $columns Column names
     *
     * @return string
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map(fn(string $column): string => $this->wrap($column), $columns));
    }

    /**
     * Convert a list of values into a comma-separated parameter string.
     *
     * @param list<mixed> $values The values to parameterize
     *
     * @return string
     */
    public function parameterize(array $values): string
    {
        return implode(', ', array_map(fn(mixed $value): string => $this->parameter($value), $values));
    }

    /**
     * Get the appropriate parameter placeholder for a value.
     *
     * @param mixed $value The value to represent as a parameter
     *
     * @return string
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
     *
     * @return bool
     */
    public function isExpression(mixed $value): bool
    {
        return $value instanceof Expression;
    }

    /**
     * Get the raw SQL string from an Expression.
     *
     * @param Expression $expression The expression
     *
     * @return string
     */
    public function getValue(Expression $expression): string
    {
        return $expression->value;
    }

    /**
     * Compile a random ordering expression.
     *
     * @return string
     */
    public function compileRandomOrder(): string
    {
        return 'RAND()';
    }
}

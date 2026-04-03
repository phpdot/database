<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query;

use Closure;
use Generator;
use PHPdot\Database\Connection;
use PHPdot\Database\Exception\RecordNotFoundException;
use PHPdot\Database\Query\Grammar\Grammar;
use PHPdot\Database\Result\CursorPaginator;
use PHPdot\Database\Result\Paginator;
use PHPdot\Database\Result\ResultSet;
use PHPdot\Database\Result\TypeCaster;
use RuntimeException;

/**
 * Fluent query builder for constructing and executing SQL queries.
 *
 * Stores query state as typed arrays and delegates to the Grammar
 * for SQL compilation. Supports SELECT, INSERT, UPDATE, DELETE,
 * joins, subqueries, CTEs, unions, aggregates, chunking, and more.
 */
final class Builder
{
    /** @var list<string|Expression> */
    private array $columns = [];

    private string $from = '';

    private ?string $fromRaw = null;

    /** @var array{query: string, alias: string}|null */
    private ?array $fromSub = null;

    private bool $distinct = false;

    /** @var list<JoinClause> */
    private array $joins = [];

    /** @var list<array<string, mixed>> */
    private array $wheres = [];

    /** @var list<mixed> */
    private array $bindings = [];

    /** @var list<string|Expression> */
    private array $groups = [];

    /** @var list<array<string, mixed>> */
    private array $havings = [];

    /** @var list<mixed> */
    private array $havingBindings = [];

    /** @var list<array<string, mixed>> */
    private array $orders = [];

    private ?int $limitValue = null;

    private ?int $offsetValue = null;

    /** @var list<array{query: self, all: bool}> */
    private array $unions = [];

    /** @var list<mixed> */
    private array $unionBindings = [];

    private string|bool $lock = false;

    /** @var list<array<string, mixed>> */
    private array $ctes = [];

    private bool $useWrite = false;

    /** @var array<string, string> */
    private array $casts = [];

    /**
     * @param Connection $connection The database connection
     * @param Grammar $grammar The SQL grammar for compilation
     */
    public function __construct(
        private Connection $connection,
        private Grammar $grammar,
    ) {}


    /**
     * Set the columns to select.
     *
     * @param list<string|Expression> $columns The columns to select
     */
    public function select(array $columns = ['*']): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Add additional columns to the select clause.
     *
     * @param list<string|Expression> $columns The columns to add
     */
    public function addSelect(array $columns): self
    {
        $this->columns = array_merge($this->columns, $columns);

        return $this;
    }

    /**
     * Set a raw SQL expression as the select clause.
     *
     * @param string $expression The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->columns = [new Expression($expression)];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Add a subquery as a select column.
     *
     * @param self|Closure $query The subquery builder or closure
     * @param string $alias The alias for the subquery column
     */
    public function selectSub(self|Closure $query, string $alias): self
    {
        if ($query instanceof Closure) {
            $sub = new self($this->connection, $this->grammar);
            $query($sub);
            $query = $sub;
        }

        $this->columns[] = new Expression('(' . $query->toSql() . ') AS ' . $this->grammar->wrap($alias));
        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Select distinct rows only.
     */
    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }


    /**
     * Set the table to query from.
     *
     * @param string $table The table name
     * @param string $alias Optional table alias
     */
    public function from(string $table, string $alias = ''): self
    {
        $this->from = $alias !== '' ? "{$table} as {$alias}" : $table;

        return $this;
    }

    /**
     * Set a subquery as the FROM source.
     *
     * @param self|Closure $query The subquery builder or closure
     * @param string $alias The alias for the derived table
     */
    public function fromSub(self|Closure $query, string $alias): self
    {
        if ($query instanceof Closure) {
            $sub = new self($this->connection, $this->grammar);
            $query($sub);
            $query = $sub;
        }

        $this->fromSub = ['query' => $query->toSql(), 'alias' => $alias];
        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Set a raw SQL expression as the FROM source.
     *
     * @param string $expression The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function fromRaw(string $expression, array $bindings = []): self
    {
        $this->fromRaw = $expression;
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }


    /**
     * Add a basic WHERE clause to the query.
     *
     * Supports three calling conventions:
     * - where('col', 'value') becomes col = value
     * - where('col', '>', 'value') becomes col > value
     * - where([['col', '>', 'value'], ...]) for multiple conditions
     * - where(Closure) for nested groups
     *
     * @param string|Closure $column The column name or closure for nested groups
     * @param mixed $operator The operator or value (when two args)
     * @param mixed $value The value to compare against
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            return $this->whereNested($column, 'and');
        }

        if ($value === null && !$this->isOperator($operator)) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => is_string($operator) ? $operator : '=',
            'value' => '?',
            'boolean' => 'and',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add multiple WHERE conditions from an array.
     *
     * @param list<array{0: string, 1?: mixed, 2?: mixed}> $conditions The conditions array
     */
    public function whereAll(array $conditions): self
    {
        foreach ($conditions as $condition) {
            $this->where($condition[0], $condition[1] ?? null, $condition[2] ?? null);
        }

        return $this;
    }

    /**
     * Add an OR WHERE clause to the query.
     *
     * @param string|Closure $column The column name or closure for nested groups
     * @param mixed $operator The operator or value (when two args)
     * @param mixed $value The value to compare against
     */
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            return $this->whereNested($column, 'or');
        }

        if ($value === null && !$this->isOperator($operator)) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => is_string($operator) ? $operator : '=',
            'value' => '?',
            'boolean' => 'or',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE NOT clause using a closure for grouping.
     *
     * @param Closure $callback The closure receiving a nested Builder
     */
    public function whereNot(Closure $callback): self
    {
        return $this->where($callback);
    }

    /**
     * Add a WHERE IN clause to the query.
     *
     * @param string $column The column name
     * @param list<mixed> $values The values to match against
     */
    public function whereIn(string $column, array $values): self
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => array_fill(0, count($values), '?'),
            'boolean' => 'and',
        ];
        foreach ($values as $val) {
            $this->bindings[] = $val;
        }

        return $this;
    }

    /**
     * Add an OR WHERE IN clause to the query.
     *
     * @param string $column The column name
     * @param list<mixed> $values The values to match against
     */
    public function orWhereIn(string $column, array $values): self
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => array_fill(0, count($values), '?'),
            'boolean' => 'or',
        ];
        foreach ($values as $val) {
            $this->bindings[] = $val;
        }

        return $this;
    }

    /**
     * Add a WHERE NOT IN clause to the query.
     *
     * @param string $column The column name
     * @param list<mixed> $values The values to exclude
     */
    public function whereNotIn(string $column, array $values): self
    {
        $this->wheres[] = [
            'type' => 'notIn',
            'column' => $column,
            'values' => array_fill(0, count($values), '?'),
            'boolean' => 'and',
        ];
        foreach ($values as $val) {
            $this->bindings[] = $val;
        }

        return $this;
    }

    /**
     * Add a WHERE BETWEEN clause to the query.
     *
     * @param string $column The column name
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     * @param bool $not Whether to negate (NOT BETWEEN)
     */
    public function whereBetween(string $column, mixed $min, mixed $max, bool $not = false): self
    {
        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => ['?', '?'],
            'not' => $not,
            'boolean' => 'and',
        ];
        $this->bindings[] = $min;
        $this->bindings[] = $max;

        return $this;
    }

    /**
     * Add a WHERE IS NULL clause to the query.
     *
     * @param string $column The column name
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a WHERE IS NOT NULL clause to the query.
     *
     * @param string $column The column name
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'notNull',
            'column' => $column,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a WHERE EXISTS subquery clause.
     *
     * @param self|Closure $query The subquery builder or closure
     */
    public function whereExists(self|Closure $query): self
    {
        if ($query instanceof Closure) {
            $sub = new self($this->connection, $this->grammar);
            $query($sub);
            $query = $sub;
        }

        $this->wheres[] = [
            'type' => 'exists',
            'query' => '(' . $query->toSql() . ')',
            'boolean' => 'and',
        ];
        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a column-to-column WHERE clause.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator
     * @param string $second The second column
     */
    public function whereColumn(string $first, string $operator, string $second): self
    {
        $this->wheres[] = [
            'type' => 'column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a raw WHERE clause to the query.
     *
     * @param string $sql The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => 'and',
        ];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Add a raw OR WHERE clause to the query.
     *
     * @param string $sql The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => 'or',
        ];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Add a WHERE DATE clause to the query.
     *
     * @param string $column The column name
     * @param string $operator The comparison operator
     * @param string $value The date value
     */
    public function whereDate(string $column, string $operator, string $value): self
    {
        return $this->addDateWhere('date', $column, $operator, $value);
    }

    /**
     * Add a WHERE MONTH clause to the query.
     *
     * @param string $column The column name
     * @param string $operator The comparison operator
     * @param string $value The month value
     */
    public function whereMonth(string $column, string $operator, string $value): self
    {
        return $this->addDateWhere('month', $column, $operator, $value);
    }

    /**
     * Add a WHERE YEAR clause to the query.
     *
     * @param string $column The column name
     * @param string $operator The comparison operator
     * @param string $value The year value
     */
    public function whereYear(string $column, string $operator, string $value): self
    {
        return $this->addDateWhere('year', $column, $operator, $value);
    }

    /**
     * Add a WHERE DAY clause to the query.
     *
     * @param string $column The column name
     * @param string $operator The comparison operator
     * @param string $value The day value
     */
    public function whereDay(string $column, string $operator, string $value): self
    {
        return $this->addDateWhere('day', $column, $operator, $value);
    }

    /**
     * Add a WHERE TIME clause to the query.
     *
     * @param string $column The column name
     * @param string $operator The comparison operator
     * @param string $value The time value
     */
    public function whereTime(string $column, string $operator, string $value): self
    {
        return $this->addDateWhere('time', $column, $operator, $value);
    }

    /**
     * Add a WHERE JSON_CONTAINS clause to the query.
     *
     * @param string $column The JSON column name
     * @param mixed $value The value to check for containment
     * @param bool $not Whether to negate the condition
     */
    public function whereJsonContains(string $column, mixed $value, bool $not = false): self
    {
        $this->wheres[] = [
            'type' => 'jsonContains',
            'column' => $column,
            'value' => '?',
            'not' => $not,
            'boolean' => 'and',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE JSON_LENGTH clause to the query.
     *
     * @param string $column The JSON column name
     * @param string $operator The comparison operator
     * @param int $value The length to compare against
     */
    public function whereJsonLength(string $column, string $operator, int $value): self
    {
        $this->wheres[] = [
            'type' => 'jsonLength',
            'column' => $column,
            'operator' => $operator,
            'value' => '?',
            'boolean' => 'and',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE LIKE clause to the query.
     *
     * @param string $column The column name
     * @param string $value The LIKE pattern
     */
    public function whereLike(string $column, string $value): self
    {
        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'value' => '?',
            'not' => false,
            'boolean' => 'and',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE NOT LIKE clause to the query.
     *
     * @param string $column The column name
     * @param string $value The LIKE pattern
     */
    public function whereNotLike(string $column, string $value): self
    {
        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'value' => '?',
            'not' => true,
            'boolean' => 'and',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE MATCH ... AGAINST (full-text) clause to the query.
     *
     * @param list<string> $columns The columns to match against
     * @param string $value The search value
     */
    public function whereFullText(array $columns, string $value): self
    {
        $this->wheres[] = [
            'type' => 'fullText',
            'columns' => $columns,
            'value' => '?',
            'boolean' => 'and',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE clause with a subquery.
     *
     * @param string $column The column name
     * @param string $operator The comparison operator
     * @param self|Closure $query The subquery builder or closure
     */
    public function whereSub(string $column, string $operator, self|Closure $query): self
    {
        if ($query instanceof Closure) {
            $sub = new self($this->connection, $this->grammar);
            $query($sub);
            $query = $sub;
        }

        $this->wheres[] = [
            'type' => 'sub',
            'column' => $column,
            'operator' => $operator,
            'query' => '(' . $query->toSql() . ')',
            'boolean' => 'and',
        ];
        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }


    /**
     * Add an INNER JOIN clause.
     *
     * @param string $table The table to join
     * @param string|Closure $first The first column or a closure for complex joins
     * @param string $operator The join operator
     * @param string $second The second column
     */
    public function join(string $table, string|Closure $first, string $operator = '', string $second = ''): self
    {
        return $this->addJoin('inner', $table, $first, $operator, $second);
    }

    /**
     * Add a LEFT JOIN clause.
     *
     * @param string $table The table to join
     * @param string|Closure $first The first column or a closure for complex joins
     * @param string $operator The join operator
     * @param string $second The second column
     */
    public function leftJoin(string $table, string|Closure $first, string $operator = '', string $second = ''): self
    {
        return $this->addJoin('left', $table, $first, $operator, $second);
    }

    /**
     * Add a RIGHT JOIN clause.
     *
     * @param string $table The table to join
     * @param string|Closure $first The first column or a closure for complex joins
     * @param string $operator The join operator
     * @param string $second The second column
     */
    public function rightJoin(string $table, string|Closure $first, string $operator = '', string $second = ''): self
    {
        return $this->addJoin('right', $table, $first, $operator, $second);
    }

    /**
     * Add a CROSS JOIN clause.
     *
     * @param string $table The table to cross join
     */
    public function crossJoin(string $table): self
    {
        $join = new JoinClause('cross', $table);
        $this->joins[] = $join;

        return $this;
    }

    /**
     * Add a JOIN with a subquery.
     *
     * @param self|Closure $query The subquery builder or closure
     * @param string $alias The alias for the derived table
     * @param string $first The first column for the ON condition
     * @param string $operator The join operator
     * @param string $second The second column for the ON condition
     */
    public function joinSub(self|Closure $query, string $alias, string $first, string $operator, string $second): self
    {
        return $this->addJoinSub('inner', $query, $alias, $first, $operator, $second);
    }

    /**
     * Add a LEFT JOIN with a subquery.
     *
     * @param self|Closure $query The subquery builder or closure
     * @param string $alias The alias for the derived table
     * @param string $first The first column for the ON condition
     * @param string $operator The join operator
     * @param string $second The second column for the ON condition
     */
    public function leftJoinSub(self|Closure $query, string $alias, string $first, string $operator, string $second): self
    {
        return $this->addJoinSub('left', $query, $alias, $first, $operator, $second);
    }

    /**
     * Add a RIGHT JOIN with a subquery.
     *
     * @param self|Closure $query The subquery builder or closure
     * @param string $alias The alias for the derived table
     * @param string $first The first column for the ON condition
     * @param string $operator The join operator
     * @param string $second The second column for the ON condition
     */
    public function rightJoinSub(self|Closure $query, string $alias, string $first, string $operator, string $second): self
    {
        return $this->addJoinSub('right', $query, $alias, $first, $operator, $second);
    }


    /**
     * Add an ORDER BY clause.
     *
     * @param string|Expression $column The column to order by
     * @param string $direction The sort direction (asc or desc)
     */
    public function orderBy(string|Expression $column, string $direction = 'asc'): self
    {
        $this->orders[] = [
            'column' => $column instanceof Expression ? $column->value : $column,
            'direction' => strtolower($direction) === 'desc' ? 'DESC' : 'ASC',
        ];

        return $this;
    }

    /**
     * Add an ORDER BY ... DESC clause.
     *
     * @param string|Expression $column The column to order by descending
     */
    public function orderByDesc(string|Expression $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add a raw ORDER BY clause.
     *
     * @param string $sql The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $this->orders[] = ['raw' => $sql];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Order by the created_at column descending (newest first).
     *
     * @param string $column The timestamp column name
     */
    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Order by the created_at column ascending (oldest first).
     *
     * @param string $column The timestamp column name
     */
    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Order results randomly.
     */
    public function inRandomOrder(): self
    {
        $this->orders[] = ['raw' => $this->grammar->compileRandomOrder()];

        return $this;
    }

    /**
     * Remove all existing ORDER BY clauses.
     */
    public function reorder(): self
    {
        $this->orders = [];

        return $this;
    }


    /**
     * Add GROUP BY columns.
     *
     * @param string|Expression ...$columns The columns to group by
     */
    public function groupBy(string|Expression ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    /**
     * Add a raw GROUP BY clause.
     *
     * @param string $sql The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function groupByRaw(string $sql, array $bindings = []): self
    {
        $this->groups[] = new Expression($sql);
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }


    /**
     * Add a HAVING clause to the query.
     *
     * @param string $column The column name
     * @param mixed $operator The operator or value (when two args)
     * @param mixed $value The value to compare against
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): self
    {
        if ($value === null && !$this->isOperator($operator)) {
            $value = $operator;
            $operator = '=';
        }

        $this->havings[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => is_string($operator) ? $operator : '=',
            'value' => '?',
            'boolean' => 'and',
        ];
        $this->havingBindings[] = $value;

        return $this;
    }

    /**
     * Add an OR HAVING clause to the query.
     *
     * @param string $column The column name
     * @param mixed $operator The operator or value (when two args)
     * @param mixed $value The value to compare against
     */
    public function orHaving(string $column, mixed $operator = null, mixed $value = null): self
    {
        if ($value === null && !$this->isOperator($operator)) {
            $value = $operator;
            $operator = '=';
        }

        $this->havings[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => is_string($operator) ? $operator : '=',
            'value' => '?',
            'boolean' => 'or',
        ];
        $this->havingBindings[] = $value;

        return $this;
    }

    /**
     * Add a raw HAVING clause to the query.
     *
     * @param string $sql The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->havings[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => 'and',
        ];
        $this->havingBindings = array_merge($this->havingBindings, $bindings);

        return $this;
    }

    /**
     * Add a raw OR HAVING clause to the query.
     *
     * @param string $sql The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function orHavingRaw(string $sql, array $bindings = []): self
    {
        $this->havings[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => 'or',
        ];
        $this->havingBindings = array_merge($this->havingBindings, $bindings);

        return $this;
    }

    /**
     * Add a HAVING BETWEEN clause to the query.
     *
     * @param string $column The column name
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     */
    public function havingBetween(string $column, mixed $min, mixed $max): self
    {
        $this->havings[] = [
            'type' => 'raw',
            'sql' => $this->grammar->wrap($column) . ' BETWEEN ? AND ?',
            'boolean' => 'and',
        ];
        $this->havingBindings[] = $min;
        $this->havingBindings[] = $max;

        return $this;
    }


    /**
     * Set the maximum number of rows to return.
     *
     * @param int $value The limit value
     */
    public function limit(int $value): self
    {
        $this->limitValue = max(0, $value);

        return $this;
    }

    /**
     * Set the number of rows to skip.
     *
     * @param int $value The offset value
     */
    public function offset(int $value): self
    {
        $this->offsetValue = max(0, $value);

        return $this;
    }

    /**
     * Alias for offset().
     *
     * @param int $value The number of rows to skip
     */
    public function skip(int $value): self
    {
        return $this->offset($value);
    }

    /**
     * Alias for limit().
     *
     * @param int $value The number of rows to take
     */
    public function take(int $value): self
    {
        return $this->limit($value);
    }

    /**
     * Set the limit and offset for a given page number.
     *
     * @param int $page The page number (1-based)
     * @param int $perPage The number of rows per page
     */
    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }


    /**
     * Lock rows for update (SELECT ... FOR UPDATE).
     */
    public function lockForUpdate(): self
    {
        $this->lock = true;

        return $this;
    }

    /**
     * Acquire a shared lock (SELECT ... LOCK IN SHARE MODE).
     */
    public function sharedLock(): self
    {
        $this->lock = 'LOCK IN SHARE MODE';

        return $this;
    }


    /**
     * Add a UNION to the query.
     *
     * @param self $query The query to union with
     */
    public function union(self $query): self
    {
        $this->unions[] = ['query' => $query, 'all' => false];
        $this->unionBindings = array_merge($this->unionBindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a UNION ALL to the query.
     *
     * @param self $query The query to union with
     */
    public function unionAll(self $query): self
    {
        $this->unions[] = ['query' => $query, 'all' => true];
        $this->unionBindings = array_merge($this->unionBindings, $query->getBindings());

        return $this;
    }


    /**
     * Add a Common Table Expression (CTE) to the query.
     *
     * @param string $name The CTE name
     * @param self|Closure $query The CTE query builder or closure
     */
    public function withCte(string $name, self|Closure $query): self
    {
        if ($query instanceof Closure) {
            $sub = new self($this->connection, $this->grammar);
            $query($sub);
            $query = $sub;
        }

        $this->ctes[] = [
            'name' => $name,
            'query' => $query->toSql(),
            'recursive' => false,
        ];
        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a recursive Common Table Expression (CTE) to the query.
     *
     * @param string $name The CTE name
     * @param self|Closure $query The CTE query builder or closure
     */
    public function withRecursiveCte(string $name, self|Closure $query): self
    {
        if ($query instanceof Closure) {
            $sub = new self($this->connection, $this->grammar);
            $query($sub);
            $query = $sub;
        }

        $this->ctes[] = [
            'name' => $name,
            'query' => $query->toSql(),
            'recursive' => true,
        ];
        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }


    /**
     * Apply a callback when the given condition is true.
     *
     * @param bool $condition The condition to evaluate
     * @param Closure(self): self $callback The callback to apply when true
     */
    public function when(bool $condition, Closure $callback): self
    {
        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Apply a callback when the given condition is false.
     *
     * @param bool $condition The condition to evaluate
     * @param Closure(self): self $callback The callback to apply when false
     */
    public function unless(bool $condition, Closure $callback): self
    {
        if (!$condition) {
            $callback($this);
        }

        return $this;
    }


    /**
     * Set column type casts for result rows.
     *
     * @param array<string, string> $casts Map of column name to type (int, float, bool, string, json, array, datetime)
     */
    public function castTypes(array $casts): self
    {
        $this->casts = $casts;

        return $this;
    }


    /**
     * Execute the SELECT query and return a ResultSet.
     */
    public function get(): ResultSet
    {
        if ($this->useWrite) {
            $this->connection->forceWriteConnection();
        }

        $sql = $this->toSql();
        $result = $this->connection->select($sql, $this->getBindings());

        if ($this->casts !== []) {
            $caster = new TypeCaster($this->casts);
            $rows = array_map(static fn(array $row): array => $caster->cast($row), $result->all());

            return new ResultSet($rows);
        }

        return $result;
    }

    /**
     * Execute the query and return the first row or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->limit(1)->get()->first();
    }

    /**
     * Execute the query and return the first row or throw an exception.
     *
     * @throws RecordNotFoundException When no record is found
     * @return array<string, mixed>
     */
    public function firstOrFail(): array
    {
        $result = $this->first();

        if ($result === null) {
            throw RecordNotFoundException::recordNotFound($this->from);
        }

        return $result;
    }

    /**
     * Execute the query and return exactly one row, or throw.
     *
     * Throws when zero or more than one row is found.
     *
     * @throws RecordNotFoundException When no record is found
     * @throws RuntimeException When more than one record is found
     * @return array<string, mixed>
     */
    public function sole(): array
    {
        $results = $this->limit(2)->get();

        if ($results->isEmpty()) {
            throw RecordNotFoundException::recordNotFound($this->from);
        }

        if ($results->count() > 1) {
            throw new RuntimeException('Multiple records found when exactly one was expected');
        }

        /** @var array<string, mixed> */
        return $results->first();
    }

    /**
     * Find a record by its primary key.
     *
     * @param mixed $id The primary key value
     * @param string $column The primary key column name
     * @return array<string, mixed>|null
     */
    public function find(mixed $id, string $column = 'id'): ?array
    {
        return $this->where($column, '=', $id)->first();
    }

    /**
     * Get a single column value from the first row.
     *
     * @param string $column The column name
     */
    public function value(string $column): mixed
    {
        $row = $this->select([$column])->first();

        if ($row === null) {
            return null;
        }

        return $row[$column] ?? null;
    }

    /**
     * Get a list of column values, optionally keyed by another column.
     *
     * @param string $column The column to extract values from
     * @param string $key The column to use as array keys
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, string $key = ''): array
    {
        return $this->get()->pluck($column, $key);
    }

    /**
     * Check if any records match the query.
     */
    public function exists(): bool
    {
        $sql = $this->grammar->compileExists($this->toSql());
        $result = $this->connection->selectOne($sql, $this->getBindings());

        if ($result === null) {
            return false;
        }

        return (bool) ($result['exists'] ?? false);
    }

    /**
     * Check if no records match the query.
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }


    /**
     * Paginate the query results with a total count.
     *
     * @param int $page The current page number (1-based)
     * @param int $perPage The number of items per page
     */
    public function paginate(int $page = 1, int $perPage = 15): Paginator
    {
        $total = (clone $this)->count();
        $results = $this->forPage($page, $perPage)->get();

        return new Paginator($results->all(), $total, $perPage, $page);
    }

    /**
     * Paginate the query results without a total count.
     *
     * @param int $page The current page number (1-based)
     * @param int $perPage The number of items per page
     */
    public function simplePaginate(int $page = 1, int $perPage = 15): Paginator
    {
        $results = $this->forPage($page, $perPage + 1)->get();
        $hasMore = $results->count() > $perPage;
        $items = $hasMore ? array_slice($results->all(), 0, $perPage) : $results->all();

        return new Paginator($items, -1, $perPage, $page);
    }

    /**
     * Paginate the query results using cursor-based pagination.
     *
     * @param int $perPage The number of items per page
     * @param string|null $cursor The opaque cursor string from a previous page
     * @param string $column The column to paginate by
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, string $column = 'id'): CursorPaginator
    {
        $builder = clone $this;

        if ($cursor !== null) {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false) {
                $builder = $builder->where($column, '>', $decoded);
            }
        }

        $results = $builder->orderBy($column)->limit($perPage + 1)->get();
        $hasMore = $results->count() > $perPage;
        $items = $hasMore ? array_slice($results->all(), 0, $perPage) : $results->all();

        $nextCursor = null;
        if ($hasMore && $items !== []) {
            $lastItem = end($items);
            $cursorValue = $lastItem[$column] ?? '';
            $nextCursor = base64_encode(is_scalar($cursorValue) ? (string) $cursorValue : '');
        }

        return new CursorPaginator($items, $perPage, $cursor, $hasMore, $nextCursor);
    }


    /**
     * Get the count of matching rows.
     *
     * @param string $column The column to count
     */
    public function count(string $column = '*'): int
    {
        $result = $this->aggregate('COUNT', $column);

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Get the sum of a column's values.
     *
     * @param string $column The column to sum
     */
    public function sum(string $column): float
    {
        $result = $this->aggregate('SUM', $column);

        return is_numeric($result) ? (float) $result : 0.0;
    }

    /**
     * Get the average of a column's values.
     *
     * @param string $column The column to average
     */
    public function avg(string $column): float
    {
        $result = $this->aggregate('AVG', $column);

        return is_numeric($result) ? (float) $result : 0.0;
    }

    /**
     * Get the minimum value of a column.
     *
     * @param string $column The column name
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Get the maximum value of a column.
     *
     * @param string $column The column name
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Alias for avg().
     *
     * @param string $column The column to average
     */
    public function average(string $column): float
    {
        return $this->avg($column);
    }


    /**
     * Insert a single row into the table.
     *
     * @param array<string, mixed> $values Column-value pairs to insert
     */
    public function insert(array $values): bool
    {
        $sql = $this->grammar->compileInsert($this->getTable(), $values);
        $bindings = $this->extractInsertBindings($values);

        return $this->connection->insert($sql, $bindings);
    }

    /**
     * Insert a row and return the auto-increment ID.
     *
     * @param array<string, mixed> $values Column-value pairs to insert
     * @param string $sequence The auto-increment column name
     */
    public function insertGetId(array $values, string $sequence = 'id'): int
    {
        $sql = $this->grammar->compileInsertGetId($this->getTable(), $values, $sequence);
        $bindings = $this->extractInsertBindings($values);

        $this->connection->ensureConnected();
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert multiple rows in a single statement.
     *
     * @param list<array<string, mixed>> $rows The rows to insert
     */
    public function insertBatch(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        $columns = array_keys($rows[0]);
        $allBindings = [];
        $paramRows = [];

        foreach ($rows as $row) {
            $rowValues = [];
            foreach ($columns as $column) {
                $val = $row[$column] ?? null;
                if ($val instanceof Expression) {
                    $rowValues[] = $val;
                } else {
                    $rowValues[] = $val;
                    $allBindings[] = $val;
                }
            }
            $paramRows[] = $rowValues;
        }

        $sql = $this->grammar->compileInsertBatch($this->getTable(), $columns, $paramRows);

        return $this->connection->insert($sql, $allBindings);
    }

    /**
     * Insert a row, ignoring duplicate key errors.
     *
     * @param array<string, mixed> $values Column-value pairs to insert
     */
    public function insertOrIgnore(array $values): bool
    {
        $sql = $this->grammar->compileInsertOrIgnore($this->getTable(), $values);
        $bindings = $this->extractInsertBindings($values);

        return $this->connection->insert($sql, $bindings);
    }

    /**
     * Insert rows from a SELECT query.
     *
     * @param list<string> $columns The destination columns
     * @param self|Closure $query The source query
     */
    public function insertUsing(array $columns, self|Closure $query): bool
    {
        if ($query instanceof Closure) {
            $sub = new self($this->connection, $this->grammar);
            $query($sub);
            $query = $sub;
        }

        $sql = $this->grammar->compileInsertUsing($this->getTable(), $columns, $query->toSql());

        return $this->connection->insert($sql, $query->getBindings());
    }

    /**
     * Update rows matching the current WHERE clauses.
     *
     * @param array<string, mixed> $values Column-value pairs to update
     * @return int The number of affected rows
     */
    public function update(array $values): int
    {
        $bindings = [];
        foreach ($values as $value) {
            if (!$value instanceof Expression) {
                $bindings[] = $value;
            }
        }
        $allBindings = array_merge($bindings, $this->bindings);

        $sql = $this->grammar->compileUpdate($this->getTable(), $values, $this->wheres, $allBindings);

        return $this->connection->update($sql, $allBindings);
    }

    /**
     * Update an existing record or insert a new one.
     *
     * @param array<string, mixed> $attributes The attributes to search by
     * @param array<string, mixed> $values The values to update or insert
     */
    public function updateOrInsert(array $attributes, array $values): bool
    {
        $existing = $this->whereAll($this->attributesToConditions($attributes))->first();

        if ($existing !== null) {
            $clone = clone $this;
            $clone->wheres = [];
            $clone->bindings = [];
            $clone->whereAll($this->attributesToConditions($attributes));
            $clone->update($values);

            return true;
        }

        return $this->insert(array_merge($attributes, $values));
    }

    /**
     * Insert or update a row based on unique constraints.
     *
     * @param array<string, mixed> $values Column-value pairs to insert
     * @param list<string> $uniqueBy Columns forming the unique constraint
     * @param list<string> $update Columns to update on conflict
     */
    public function upsert(array $values, array $uniqueBy, array $update): bool
    {
        $sql = $this->grammar->compileUpsert($this->getTable(), $values, $uniqueBy, $update);
        $bindings = $this->extractInsertBindings($values);

        return $this->connection->insert($sql, $bindings);
    }

    /**
     * Delete rows matching the current WHERE clauses.
     *
     * @return int The number of affected rows
     */
    public function delete(): int
    {
        $sql = $this->grammar->compileDelete($this->getTable(), $this->wheres, $this->bindings);

        return $this->connection->delete($sql, $this->bindings);
    }

    /**
     * Truncate the table, removing all rows.
     */
    public function truncate(): void
    {
        $sql = $this->grammar->compileTruncate($this->getTable());
        $this->connection->statement($sql);
    }

    /**
     * Increment a column's value.
     *
     * @param string $column The column to increment
     * @param int|float $amount The amount to increment by
     * @param array<string, mixed> $extra Additional columns to update
     * @return int The number of affected rows
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $values = array_merge(
            [$column => new Expression($this->grammar->wrap($column) . ' + ' . $amount)],
            $extra,
        );

        return $this->update($values);
    }

    /**
     * Decrement a column's value.
     *
     * @param string $column The column to decrement
     * @param int|float $amount The amount to decrement by
     * @param array<string, mixed> $extra Additional columns to update
     * @return int The number of affected rows
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        $values = array_merge(
            [$column => new Expression($this->grammar->wrap($column) . ' - ' . $amount)],
            $extra,
        );

        return $this->update($values);
    }


    /**
     * Process results in chunks for memory-efficient iteration.
     *
     * @param int $count The number of rows per chunk
     * @param Closure(ResultSet, int): (void|false) $callback The callback for each chunk
     * @return bool False if callback returned false, true otherwise
     */
    public function chunk(int $count, Closure $callback): bool
    {
        $page = 1;

        do {
            $results = (clone $this)->forPage($page, $count)->get();

            if ($results->isEmpty()) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while ($results->count() === $count);

        return true;
    }

    /**
     * Chunk results by ID for stable pagination.
     *
     * @param int $count The number of rows per chunk
     * @param Closure(ResultSet, int): (void|false) $callback The callback for each chunk
     * @param string $column The column to chunk by (typically the primary key)
     * @return bool False if callback returned false, true otherwise
     */
    public function chunkById(int $count, Closure $callback, string $column = 'id'): bool
    {
        $lastId = null;
        $page = 1;

        do {
            $clone = clone $this;
            if ($lastId !== null) {
                $clone->where($column, '>', $lastId);
            }

            $results = $clone->orderBy($column)->limit($count)->get();

            if ($results->isEmpty()) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $lastRow = $results->last();
            $lastId = $lastRow[$column] ?? null;
            $page++;
        } while ($results->count() === $count);

        return true;
    }

    /**
     * Lazily iterate over results in chunks using a Generator.
     *
     * @param int $chunkSize The number of rows per chunk
     * @return Generator<int, array<string, mixed>>
     */
    public function lazy(int $chunkSize = 1000): Generator
    {
        $page = 1;

        while (true) {
            $results = (clone $this)->forPage($page, $chunkSize)->get();

            foreach ($results as $row) {
                yield $row;
            }

            if ($results->count() < $chunkSize) {
                break;
            }

            $page++;
        }
    }

    /**
     * Lazily iterate over results by ID for stable pagination.
     *
     * @param int $chunkSize The number of rows per chunk
     * @param string $column The column to chunk by
     * @return Generator<int, array<string, mixed>>
     */
    public function lazyById(int $chunkSize = 1000, string $column = 'id'): Generator
    {
        $lastId = null;

        while (true) {
            $clone = clone $this;
            if ($lastId !== null) {
                $clone->where($column, '>', $lastId);
            }

            $results = $clone->orderBy($column)->limit($chunkSize)->get();

            foreach ($results as $row) {
                yield $row;
            }

            if ($results->count() < $chunkSize) {
                break;
            }

            $lastRow = $results->last();
            $lastId = $lastRow[$column] ?? null;
        }
    }

    /**
     * Lazily iterate over results by ID in descending order.
     *
     * @param int $chunkSize The number of rows per chunk
     * @param string $column The column to chunk by
     * @return Generator<int, array<string, mixed>>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, string $column = 'id'): Generator
    {
        $lastId = null;

        while (true) {
            $clone = clone $this;
            if ($lastId !== null) {
                $clone->where($column, '<', $lastId);
            }

            $results = $clone->orderByDesc($column)->limit($chunkSize)->get();

            foreach ($results as $row) {
                yield $row;
            }

            if ($results->count() < $chunkSize) {
                break;
            }

            $lastRow = $results->last();
            $lastId = $lastRow[$column] ?? null;
        }
    }


    /**
     * Compile the query to a SQL string with placeholders.
     */
    public function toSql(): string
    {
        return $this->grammar->compileSelect($this->toQueryArray());
    }

    /**
     * Compile the query to a raw SQL string with bindings substituted.
     *
     * WARNING: For debugging only. Never execute the output directly.
     */
    public function toRawSql(): string
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();

        foreach ($bindings as $binding) {
            if (is_string($binding)) {
                $value = "'" . addslashes($binding) . "'";
            } elseif ($binding === null) {
                $value = 'NULL';
            } elseif (is_bool($binding)) {
                $value = $binding ? '1' : '0';
            } elseif (is_int($binding) || is_float($binding)) {
                $value = (string) $binding;
            } else {
                $value = "'[object]'";
            }

            $pos = strpos($sql, '?');
            if ($pos !== false) {
                $sql = substr_replace($sql, $value, $pos, 1);
            }
        }

        return $sql;
    }

    /**
     * Get all parameter bindings for the query.
     *
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        $joinBindings = [];
        foreach ($this->joins as $join) {
            $joinBindings = array_merge($joinBindings, $join->bindings);
        }

        return array_merge($joinBindings, $this->bindings, $this->havingBindings, $this->unionBindings);
    }

    /**
     * Dump the compiled SQL and bindings to stdout and return self.
     */
    public function dump(): self
    {
        echo $this->toRawSql() . "\n";

        return $this;
    }

    /**
     * Dump the compiled SQL and bindings to stdout and terminate.
     */
    public function dd(): never
    {
        echo $this->toRawSql() . "\n";

        exit(1);
    }


    /**
     * Deep clone all mutable array state.
     */
    public function __clone()
    {
        $clonedJoins = [];
        foreach ($this->joins as $join) {
            $clonedJoins[] = clone $join;
        }
        $this->joins = $clonedJoins;

        $clonedUnions = [];
        foreach ($this->unions as $union) {
            $clonedUnions[] = ['query' => clone $union['query'], 'all' => $union['all']];
        }
        $this->unions = $clonedUnions;
    }


    /**
     * Force this query to use the write connection.
     */
    public function useWriteConnection(): self
    {
        $this->useWrite = true;

        return $this;
    }

    /**
     * Check if this query should use the write connection.
     */
    public function shouldUseWriteConnection(): bool
    {
        return $this->useWrite;
    }


    /**
     * Build the component array expected by the Grammar.
     *
     * @return array{
     *     columns: list<string|Expression>,
     *     from: string,
     *     fromRaw: string|null,
     *     fromSub: array{query: string, alias: string}|null,
     *     distinct: bool,
     *     joins: list<array<string, mixed>>,
     *     wheres: list<array<string, mixed>>,
     *     groups: list<string>,
     *     havings: list<array<string, mixed>>,
     *     orders: list<array<string, mixed>>,
     *     limit: int|null,
     *     offset: int|null,
     *     unions: list<array<string, mixed>>,
     *     lock: string|bool,
     *     ctes: list<array<string, mixed>>,
     * }
     */
    private function toQueryArray(): array
    {
        $groups = [];
        foreach ($this->groups as $group) {
            $groups[] = $group instanceof Expression ? $group->value : $group;
        }

        $unions = [];
        foreach ($this->unions as $union) {
            $unions[] = ['query' => $union['query']->toSql(), 'all' => $union['all']];
        }

        return [
            'columns' => $this->columns !== [] ? $this->columns : ['*'],
            'from' => $this->from,
            'fromRaw' => $this->fromRaw,
            'fromSub' => $this->fromSub,
            'distinct' => $this->distinct,
            'joins' => array_map(fn(JoinClause $j): array => $this->joinToArray($j), $this->joins),
            'wheres' => $this->wheres,
            'groups' => $groups,
            'havings' => $this->havings,
            'orders' => $this->orders,
            'limit' => $this->limitValue,
            'offset' => $this->offsetValue,
            'unions' => $unions,
            'lock' => $this->lock,
            'ctes' => $this->ctes,
        ];
    }

    /**
     * Convert a JoinClause to the array format expected by the Grammar.
     *
     * @param JoinClause $join The join clause to convert
     * @return array<string, mixed>
     */
    private function joinToArray(JoinClause $join): array
    {
        return [
            'type' => $join->type,
            'table' => $join->table,
            'clauses' => $join->clauses,
        ];
    }

    /**
     * Add a nested WHERE group.
     *
     * @param Closure $callback The closure receiving a nested Builder
     * @param string $boolean The boolean connector (and/or)
     */
    private function whereNested(Closure $callback, string $boolean): self
    {
        $nested = new self($this->connection, $this->grammar);
        $nested->from = $this->from;
        $callback($nested);

        if ($nested->wheres !== []) {
            $nestedSql = $this->grammar->compileWheres($nested->wheres);
            $nestedWhere = substr($nestedSql, 6);

            if ($nestedWhere !== '') {
                $this->wheres[] = [
                    'type' => 'nested',
                    'query' => $nestedWhere,
                    'boolean' => $boolean,
                ];
                $this->bindings = array_merge($this->bindings, $nested->bindings);
            }
        }

        return $this;
    }

    /**
     * Add a date-based WHERE clause.
     *
     * @param string $dateType The date function type (date, month, year, day, time)
     * @param string $column The column name
     * @param string $operator The comparison operator
     * @param string $value The value to compare against
     */
    private function addDateWhere(string $dateType, string $column, string $operator, string $value): self
    {
        $this->wheres[] = [
            'type' => 'date',
            'dateType' => $dateType,
            'column' => $column,
            'operator' => $operator,
            'value' => '?',
            'boolean' => 'and',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a JOIN clause to the query.
     *
     * @param string $type The join type (inner, left, right)
     * @param string $table The table to join
     * @param string|Closure $first The first column or closure for complex joins
     * @param string $operator The comparison operator
     * @param string $second The second column
     */
    private function addJoin(string $type, string $table, string|Closure $first, string $operator, string $second): self
    {
        $join = new JoinClause($type, $table);

        if ($first instanceof Closure) {
            $first($join);
        } else {
            $join->on($first, $operator, $second);
        }

        $this->joins[] = $join;

        return $this;
    }

    /**
     * Add a JOIN with a subquery.
     *
     * @param string $type The join type (inner, left, right)
     * @param self|Closure $query The subquery builder or closure
     * @param string $alias The alias for the derived table
     * @param string $first The first column for the ON condition
     * @param string $operator The comparison operator
     * @param string $second The second column
     */
    private function addJoinSub(string $type, self|Closure $query, string $alias, string $first, string $operator, string $second): self
    {
        if ($query instanceof Closure) {
            $sub = new self($this->connection, $this->grammar);
            $query($sub);
            $query = $sub;
        }

        $subSql = '(' . $query->toSql() . ') as ' . $alias;
        $join = new JoinClause($type, $subSql);
        $join->on($first, $operator, $second);

        $this->joins[] = $join;
        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Run an aggregate function against the query.
     *
     * @param string $function The aggregate function (COUNT, SUM, AVG, MIN, MAX)
     * @param string $column The column to aggregate
     */
    private function aggregate(string $function, string $column): mixed
    {
        $clone = clone $this;
        $clone->columns = [new Expression($function . '(' . $this->grammar->wrap($column) . ') AS ' . $this->grammar->wrap('aggregate'))];
        $clone->orders = [];
        $clone->limitValue = null;
        $clone->offsetValue = null;

        $result = $clone->get()->first();

        if ($result === null) {
            return 0;
        }

        return $result['aggregate'] ?? 0;
    }

    /**
     * Extract parameter bindings from an insert values array, skipping Expressions.
     *
     * @param array<string, mixed> $values The column-value pairs
     * @return list<mixed>
     */
    private function extractInsertBindings(array $values): array
    {
        $bindings = [];
        foreach ($values as $value) {
            if (!$value instanceof Expression) {
                $bindings[] = $value;
            }
        }

        return $bindings;
    }

    /**
     * Convert an associative array of attributes to a list of where conditions.
     *
     * @param array<string, mixed> $attributes The attributes
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function attributesToConditions(array $attributes): array
    {
        $conditions = [];
        foreach ($attributes as $column => $value) {
            $conditions[] = [$column, '=', $value];
        }

        return $conditions;
    }

    /**
     * Determine if the given value is a valid SQL operator.
     *
     * @param mixed $value The value to check
     */
    private function isOperator(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower($value), [
            '=', '<', '>', '<=', '>=', '<>', '!=', 'like', 'not like',
        ], true);
    }

    /**
     * Get the table name for write operations (without alias).
     */
    private function getTable(): string
    {
        $table = $this->from;

        $lower = strtolower($table);
        $asPos = strpos($lower, ' as ');
        if ($asPos !== false) {
            return substr($table, 0, $asPos);
        }

        return $table;
    }
}

<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query;

/**
 * Represents a single JOIN clause with its conditions.
 *
 * Supports ON column comparisons, WHERE value comparisons,
 * and various condition types that can be combined with AND/OR.
 */
final class JoinClause
{
    /** @var list<array<string, mixed>> */
    public array $clauses = [];

    /** @var list<mixed> */
    public array $bindings = [];

    /**
     * @param string $type The join type (inner, left, right, cross)
     * @param string $table The table to join
     */
    public function __construct(
        public string $type,
        public string $table,
    ) {}

    /**
     * Add an ON column comparison condition.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator
     * @param string $second The second column
     */
    public function on(string $first, string $operator, string $second): self
    {
        $this->clauses[] = [
            'type' => 'on',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add an OR ON column comparison condition.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator
     * @param string $second The second column
     */
    public function orOn(string $first, string $operator, string $second): self
    {
        $this->clauses[] = [
            'type' => 'on',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => 'or',
        ];

        return $this;
    }

    /**
     * Add a WHERE value comparison condition to the join.
     *
     * @param string $column The column name
     * @param mixed $operator The operator or value (when two args)
     * @param mixed $value The value to compare against
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        [$operator, $value] = $this->prepareValueAndOperator($operator, $value);

        $this->clauses[] = [
            'type' => 'where',
            'column' => $column,
            'operator' => $operator,
            'value' => '?',
            'boolean' => 'and',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add an OR WHERE value comparison condition to the join.
     *
     * @param string $column The column name
     * @param mixed $operator The operator or value (when two args)
     * @param mixed $value The value to compare against
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        [$operator, $value] = $this->prepareValueAndOperator($operator, $value);

        $this->clauses[] = [
            'type' => 'where',
            'column' => $column,
            'operator' => $operator,
            'value' => '?',
            'boolean' => 'or',
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE IS NULL condition to the join.
     *
     * @param string $column The column name
     */
    public function whereNull(string $column): self
    {
        $this->clauses[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a WHERE IS NOT NULL condition to the join.
     *
     * @param string $column The column name
     */
    public function whereNotNull(string $column): self
    {
        $this->clauses[] = [
            'type' => 'notNull',
            'column' => $column,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a WHERE IN condition to the join.
     *
     * @param string $column The column name
     * @param list<mixed> $values The values to match against
     */
    public function whereIn(string $column, array $values): self
    {
        $placeholders = array_fill(0, count($values), '?');
        $this->clauses[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $placeholders,
            'boolean' => 'and',
        ];
        foreach ($values as $val) {
            $this->bindings[] = $val;
        }

        return $this;
    }

    /**
     * Add a WHERE NOT IN condition to the join.
     *
     * @param string $column The column name
     * @param list<mixed> $values The values to exclude
     */
    public function whereNotIn(string $column, array $values): self
    {
        $placeholders = array_fill(0, count($values), '?');
        $this->clauses[] = [
            'type' => 'notIn',
            'column' => $column,
            'values' => $placeholders,
            'boolean' => 'and',
        ];
        foreach ($values as $val) {
            $this->bindings[] = $val;
        }

        return $this;
    }

    /**
     * Add a column-to-column comparison condition to the join.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator
     * @param string $second The second column
     */
    public function whereColumn(string $first, string $operator, string $second): self
    {
        $this->clauses[] = [
            'type' => 'column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a raw SQL condition to the join.
     *
     * @param string $sql The raw SQL expression
     * @param list<mixed> $bindings Parameter bindings for the expression
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->clauses[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => 'and',
        ];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Prepare the operator and value for a where clause, handling two-argument calls.
     *
     * @param mixed $operator The operator or value
     * @param mixed $value The value or null
     * @return array{0: string, 1: mixed}
     */
    private function prepareValueAndOperator(mixed $operator, mixed $value): array
    {
        if ($value === null && !$this->isOperator($operator)) {
            return ['=', $operator];
        }

        return [is_string($operator) ? $operator : '=', $value];
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
}

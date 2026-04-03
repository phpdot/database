<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Result;

use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;

/**
 * Immutable result set wrapping database query rows.
 *
 * Implements Countable and IteratorAggregate for convenient iteration
 * and counting. All mutation methods return new instances.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final readonly class ResultSet implements Countable, IteratorAggregate
{
    /**
     * @param list<array<string, mixed>> $rows The result rows
     */
    public function __construct(
        private array $rows,
    ) {}

    /**
     * Get all rows.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->rows;
    }

    /**
     * Get the first row, or null if empty.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * Get the last row, or null if empty.
     *
     * @return array<string, mixed>|null
     */
    public function last(): ?array
    {
        if ($this->rows === []) {
            return null;
        }

        return $this->rows[count($this->rows) - 1];
    }

    /**
     * Get the number of rows.
     */
    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * Check if the result set is empty.
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Check if the result set is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->rows !== [];
    }

    /**
     * Extract a single column's values, optionally keyed by another column.
     *
     * `pluck('email')` returns `['a@x.com', 'b@x.com']`
     * `pluck('email', 'id')` returns `[1 => 'a@x.com', 2 => 'b@x.com']`
     *
     * @param string $column The column to extract values from
     * @param string $key The column to use as array keys (empty string for numeric keys)
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, string $key = ''): array
    {
        if ($key === '') {
            $result = [];
            foreach ($this->rows as $row) {
                $result[] = $row[$column] ?? null;
            }

            return $result;
        }

        $result = [];
        foreach ($this->rows as $row) {
            /** @var int|string $keyValue */
            $keyValue = $row[$key] ?? 0;
            $result[$keyValue] = $row[$column] ?? null;
        }

        return $result;
    }

    /**
     * Get a single value from the first row.
     *
     * @param string $column The column name
     */
    public function value(string $column): mixed
    {
        $first = $this->first();

        if ($first === null) {
            return null;
        }

        return $first[$column] ?? null;
    }

    /**
     * Get all values for a single column.
     *
     * @param string $column The column name
     * @return list<mixed>
     */
    public function column(string $column): array
    {
        $result = [];
        foreach ($this->rows as $row) {
            $result[] = $row[$column] ?? null;
        }

        return $result;
    }

    /**
     * Index all rows by a given column's value.
     *
     * @param string $column The column to use as array keys
     * @return array<int|string, array<string, mixed>>
     */
    public function keyBy(string $column): array
    {
        $result = [];
        foreach ($this->rows as $row) {
            /** @var int|string $key */
            $key = $row[$column] ?? 0;
            $result[$key] = $row;
        }

        return $result;
    }

    /**
     * Map each row through a callback, returning a new ResultSet.
     *
     * @param Closure(array<string, mixed>): array<string, mixed> $callback
     */
    public function map(Closure $callback): self
    {
        return new self(array_map($callback, $this->rows));
    }

    /**
     * Filter rows through a callback, returning a new ResultSet.
     *
     * @param Closure(array<string, mixed>): bool $callback
     */
    public function filter(Closure $callback): self
    {
        return new self(array_values(array_filter($this->rows, $callback)));
    }

    /**
     * Iterate over each row for side effects. Returns self.
     *
     * @param Closure(array<string, mixed>): void $callback
     */
    public function each(Closure $callback): self
    {
        foreach ($this->rows as $row) {
            $callback($row);
        }

        return $this;
    }

    /**
     * Return a new ResultSet with rows unique by a given column.
     *
     * @param string $column The column to check uniqueness against
     */
    public function unique(string $column): self
    {
        $seen = [];
        $result = [];

        foreach ($this->rows as $row) {
            $value = $row[$column] ?? null;
            $key = serialize($value);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $row;
            }
        }

        return new self($result);
    }

    /**
     * Convert the result set to a plain array.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->rows;
    }

    /**
     * Convert the result set to a JSON string.
     *
     * @param int $options JSON encoding options bitmask
     */
    public function toJson(int $options = 0): string
    {
        $json = json_encode($this->rows, $options);

        if ($json === false) {
            return '[]';
        }

        return $json;
    }

    /**
     * Get an iterator for the rows.
     *
     * @return ArrayIterator<int, array<string, mixed>>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->rows);
    }
}

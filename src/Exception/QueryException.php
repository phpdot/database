<?php

declare(strict_types=1);

/**
 * Query Exception
 *
 * Thrown when a SQL query fails to execute, capturing the offending SQL and its bindings.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

use Throwable;

final class QueryException extends DatabaseException
{
    /** */
    private readonly string $sql;

    /**
     * @var array<int|string, mixed>
     */
    private readonly array $bindings;

    /**
     * @param string $sql The SQL query that failed
     * @param array<int|string, mixed> $bindings The parameter bindings
     * @param string $message Error description
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $sql,
        array $bindings,
        string $message,
        ?Throwable $previous = null,
    ) {
        $this->sql = $sql;
        $this->bindings = $bindings;

        parent::__construct($message, 0, $previous);
    }

    /**
     * The SQL statement that triggered this exception.
     *
     * @return string The SQL query that caused the exception
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * The parameter bindings that were bound to the failed statement.
     *
     * @return array<int|string, mixed> The parameter bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Build an exception for a query that failed to execute.
     *
     * @param string $sql The SQL query that failed
     * @param array<int|string, mixed> $bindings The parameter bindings
     * @param string $error Error message from the driver
     * @param \Throwable|null $previous The originating driver exception, preserved for the chain
     *
     * @return QueryException
     */
    public static function executionFailed(string $sql, array $bindings, string $error, ?Throwable $previous = null): self
    {
        return new self(
            $sql,
            $bindings,
            sprintf('Query execution failed: %s (SQL: %s)', $error, $sql),
            $previous,
        );
    }

    /**
     * Build an exception for a query aborted by a deadlock.
     *
     * @param string $sql The SQL query that caused a deadlock
     *
     * @return QueryException
     */
    public static function deadlock(string $sql): self
    {
        return new self(
            $sql,
            [],
            sprintf('Deadlock detected (SQL: %s)', $sql),
        );
    }

    /**
     * Build an exception for a query that exceeded its time limit.
     *
     * @param string $sql The SQL query that timed out
     *
     * @return QueryException
     */
    public static function timeout(string $sql): self
    {
        return new self(
            $sql,
            [],
            sprintf('Query timed out (SQL: %s)', $sql),
        );
    }
}

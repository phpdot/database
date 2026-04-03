<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

use Throwable;

final class QueryException extends DatabaseException
{
    /**  */
    private readonly string $sql;

    /** @var array<int|string, mixed> */
    private readonly array $bindings;

    /**
     * @param string $sql The SQL query that failed
     * @param array<int|string, mixed> $bindings The parameter bindings
     * @param string $message Error description
     * @param Throwable|null $previous Previous exception
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
     * @return string The SQL query that caused the exception
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<int|string, mixed> The parameter bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * @param string $sql The SQL query that failed
     * @param array<int|string, mixed> $bindings The parameter bindings
     * @param string $error Error message from the driver
     */
    public static function executionFailed(string $sql, array $bindings, string $error): self
    {
        return new self(
            $sql,
            $bindings,
            sprintf('Query execution failed: %s (SQL: %s)', $error, $sql),
        );
    }

    /**
     * @param string $sql The SQL query that caused a deadlock
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
     * @param string $sql The SQL query that timed out
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

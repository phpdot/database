<?php

declare(strict_types=1);

/**
 * Schema Exception
 *
 * Exception thrown for database schema errors such as missing tables or columns.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class SchemaException extends DatabaseException
{
    /**
     * Build an exception for an operation on a missing table.
     *
     * @param string $table The table that was not found
     *
     * @return self
     */
    public static function tableNotFound(string $table): self
    {
        return new self(
            sprintf('Table "%s" does not exist', $table),
        );
    }

    /**
     * Build an exception for creating a table that already exists.
     *
     * @param string $table The table that already exists
     *
     * @return SchemaException
     */
    public static function tableAlreadyExists(string $table): self
    {
        return new self(
            sprintf('Table "%s" already exists', $table),
        );
    }

    /**
     * Build an exception for an operation on a missing column.
     *
     * @param string $table The table name
     * @param string $column The column that was not found
     *
     * @return SchemaException
     */
    public static function columnNotFound(string $table, string $column): self
    {
        return new self(
            sprintf('Column "%s" does not exist in table "%s"', $column, $table),
        );
    }

    /**
     * Build an exception for an operation the driver does not support.
     *
     * @param string $operation The unsupported operation
     * @param string $driver The database driver
     *
     * @return SchemaException
     */
    public static function unsupportedOperation(string $operation, string $driver): self
    {
        return new self(
            sprintf('Operation "%s" is not supported by the "%s" driver', $operation, $driver),
        );
    }
}

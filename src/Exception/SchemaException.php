<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class SchemaException extends DatabaseException
{
    /**
     * @param string $table The table that was not found
     */
    public static function tableNotFound(string $table): self
    {
        return new self(
            sprintf('Table "%s" does not exist', $table),
        );
    }

    /**
     * @param string $table The table that already exists
     */
    public static function tableAlreadyExists(string $table): self
    {
        return new self(
            sprintf('Table "%s" already exists', $table),
        );
    }

    /**
     * @param string $table The table name
     * @param string $column The column that was not found
     */
    public static function columnNotFound(string $table, string $column): self
    {
        return new self(
            sprintf('Column "%s" does not exist in table "%s"', $column, $table),
        );
    }

    /**
     * @param string $operation The unsupported operation
     * @param string $driver The database driver
     */
    public static function unsupportedOperation(string $operation, string $driver): self
    {
        return new self(
            sprintf('Operation "%s" is not supported by the "%s" driver', $operation, $driver),
        );
    }
}

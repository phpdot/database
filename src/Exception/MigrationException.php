<?php

declare(strict_types=1);

/**
 * Migration Exception
 *
 * Thrown when a database migration fails to run, roll back, or set up its tracking table.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class MigrationException extends DatabaseException
{
    /**
     * Build an exception for a migration that failed to run.
     *
     * @param string $migration The migration that failed
     * @param string $error Error message from the driver
     *
     * @return self
     */
    public static function migrationFailed(string $migration, string $error): self
    {
        return new self(
            sprintf('Migration "%s" failed: %s', $migration, $error),
        );
    }

    /**
     * Build an exception for a migrations table that could not be created.
     *
     * @return self When the migrations table could not be created
     */
    public static function tableNotCreated(): self
    {
        return new self('Failed to create migrations table');
    }

    /**
     * Build an exception for a migration rollback that failed.
     *
     * @param string $migration The migration that failed to roll back
     * @param string $error Error message from the driver
     *
     * @return MigrationException
     */
    public static function rollbackFailed(string $migration, string $error): self
    {
        return new self(
            sprintf('Rollback of migration "%s" failed: %s', $migration, $error),
        );
    }
}

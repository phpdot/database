<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class MigrationException extends DatabaseException
{
    /**
     * @param string $migration The migration that failed
     * @param string $error Error message from the driver
     */
    public static function migrationFailed(string $migration, string $error): self
    {
        return new self(
            sprintf('Migration "%s" failed: %s', $migration, $error),
        );
    }

    /**
     * @return self When the migrations table could not be created
     */
    public static function tableNotCreated(): self
    {
        return new self('Failed to create migrations table');
    }

    /**
     * @param string $migration The migration that failed to roll back
     * @param string $error Error message from the driver
     */
    public static function rollbackFailed(string $migration, string $error): self
    {
        return new self(
            sprintf('Rollback of migration "%s" failed: %s', $migration, $error),
        );
    }
}

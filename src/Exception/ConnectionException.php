<?php

declare(strict_types=1);

/**
 * Connection Exception
 *
 * Thrown when a database connection cannot be established, is misconfigured, or is lost.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class ConnectionException extends DatabaseException
{
    /**
     * Build an exception for a failed connection attempt.
     *
     * @param string $driver Database driver name
     * @param string $host Database host
     * @param string $error Error message from the driver
     *
     * @return self
     */
    public static function connectionFailed(string $driver, string $host, string $error): self
    {
        return new self(
            sprintf('Failed to connect to %s at %s: %s', $driver, $host, $error),
        );
    }

    /**
     * Build an exception for a connection name that is not configured.
     *
     * @param string $name The requested connection name
     *
     * @return ConnectionException
     */
    public static function unknownConnection(string $name): self
    {
        return new self(
            sprintf("No database connection named '%s' is configured.", $name),
        );
    }

    /**
     * Build an exception for an invalid connection configuration.
     *
     * @param string $name The connection name
     * @param string $reason Why the configuration is invalid
     *
     * @return ConnectionException
     */
    public static function invalidConfiguration(string $name, string $reason): self
    {
        return new self(
            sprintf("Invalid configuration for database connection '%s': %s", $name, $reason),
        );
    }

    /**
     * Build an exception for a failed reconnection attempt.
     *
     * @param string $error Error message from the driver
     *
     * @return ConnectionException
     */
    public static function reconnectFailed(string $error): self
    {
        return new self(
            sprintf('Failed to reconnect: %s', $error),
        );
    }

    /**
     * Build an exception for a connection lost mid-use.
     *
     * @param string $error Error message from the driver
     *
     * @return ConnectionException
     */
    public static function disconnected(string $error): self
    {
        return new self(
            sprintf('DatabaseConnection lost: %s', $error),
        );
    }
}

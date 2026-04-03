<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class ConnectionException extends DatabaseException
{
    /**
     * @param string $driver Database driver name
     * @param string $host Database host
     * @param string $error Error message from the driver
     */
    public static function connectionFailed(string $driver, string $host, string $error): self
    {
        return new self(
            sprintf('Failed to connect to %s at %s: %s', $driver, $host, $error),
        );
    }

    /**
     * @param string $error Error message from the driver
     */
    public static function reconnectFailed(string $error): self
    {
        return new self(
            sprintf('Failed to reconnect: %s', $error),
        );
    }

    /**
     * @param string $error Error message from the driver
     */
    public static function disconnected(string $error): self
    {
        return new self(
            sprintf('Connection lost: %s', $error),
        );
    }
}

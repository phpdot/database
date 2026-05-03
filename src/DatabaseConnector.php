<?php

declare(strict_types=1);

/**
 * Adapts `phpdot/database`'s `DatabaseConnection` to `phpdot/pool`'s connector contract.
 *
 * Lets any pool (e.g., `phpdot/pool`) hold and manage `DatabaseConnection` instances:
 * `connect()` builds a fresh `DatabaseConnection` and ensures it's connected; `isAlive()`
 * issues a `SELECT 1` ping; `close()` shuts the underlying DBAL connection down.
 *
 * The connector itself depends only on `PHPdot\Contracts\Pool\ConnectorInterface`
 * — it does not require `phpdot/pool` at runtime, so `phpdot/database` stays
 * usable in non-pooled contexts (FPM, CLI) without pulling pooling code along.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database;

use PHPdot\Contracts\Pool\ConnectorInterface;
use PHPdot\Database\Config\DatabaseConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DatabaseConnector implements ConnectorInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build a fresh `DatabaseConnection`, ensuring it is connected before handing back.
     */
    public function connect(): object
    {
        $connection = new DatabaseConnection($this->config, $this->logger);
        $connection->ensureConnected();

        return $connection;
    }

    /**
     * Ping the connection. Returns `false` on any error.
     */
    public function isAlive(object $connection): bool
    {
        if (!$connection instanceof DatabaseConnection) {
            return false;
        }

        try {
            return $connection->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Close the connection. Never throws.
     */
    public function close(object $connection): void
    {
        if (!$connection instanceof DatabaseConnection) {
            return;
        }

        try {
            $connection->close();
        } catch (\Throwable) {
            // close() must not throw — ignore failures
        }
    }
}

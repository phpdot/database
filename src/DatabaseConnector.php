<?php

declare(strict_types=1);

/**
 * Database Connector
 *
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
use PHPdot\Database\Connection\ConnectionConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DatabaseConnector implements ConnectorInterface
{
    private readonly LoggerInterface $logger;

    /**
     * Wire the connector to a resolved config and optional logger.
     *
     * @param ConnectionConfig $config The resolved connection configuration to pool
     * @param LoggerInterface|null $logger PSR-3 logger, or null for a NullLogger
     */
    public function __construct(
        private readonly ConnectionConfig $config,
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
     * Reset per-borrow state, then ping. Returns `false` on any error.
     *
     * Pools call isAlive() on release (and on borrow-after-idle), which is the
     * point at which a connection must be scrubbed before the next coroutine
     * reuses it: any leftover-open transaction is rolled back and per-request
     * flags (sticky read/write routing, query log) are cleared. Resetting an
     * already-clean connection is a no-op, so this is safe on every call site.
     */
    public function isAlive(object $connection): bool
    {
        if (!$connection instanceof DatabaseConnection) {
            return false;
        }

        try {
            $connection->reset();

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
        }
    }
}

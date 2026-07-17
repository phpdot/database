<?php

declare(strict_types=1);

/**
 * Resolves a raw connection parameter block into the driver-specific
 * ConnectionConfig for its 'driver', and builds connections or pool connectors
 * from it. Each driver validates its own block, so misconfiguration fails fast
 * with a message naming the connection and the offending key.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Connection;

use InvalidArgumentException;
use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\Connection\Postgres\PostgresConfig;
use PHPdot\Database\Connection\Sqlite\SqliteConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\DatabaseConnector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ConnectionFactory
{
    /**
     * Resolve a parameter block into its driver-specific configuration.
     *
     * @param string $name The connection name (used in validation messages)
     * @param array<string, mixed> $block A parameter block whose 'driver' key selects the engine
     *
     * @throws InvalidArgumentException When the driver is missing or unsupported
     *
     * @return ConnectionConfig
     */
    public function config(string $name, array $block): ConnectionConfig
    {
        $driver = ConfigValue::string($block, 'driver', '');

        return match ($driver) {
            'mysql' => MySqlConfig::fromArray($name, $block),
            'pgsql' => $this->postgres($name, $block),
            'sqlite' => SqliteConfig::fromArray($name, $block),
            default => throw new InvalidArgumentException(
                $driver === ''
                    ? "Database connection '{$name}' is missing a 'driver'."
                    : "Database connection '{$name}' has an unsupported driver: '{$driver}'.",
            ),
        };
    }

    /**
     * Build a PostgreSQL config from a raw parameter block.
     *
     * @param array<string, mixed> $block
     * @param string $name
     *
     * @return PostgresConfig
     */
    private function postgres(string $name, array $block): PostgresConfig
    {
        if (PHP_OS_FAMILY === 'Darwin' && getenv('PGGSSENCMODE') === false) {
            putenv('PGGSSENCMODE=disable');
        }

        return PostgresConfig::fromArray($name, $block);
    }

    /**
     * Build a DatabaseConnection from a parameter block.
     *
     * @param string $name The connection name
     * @param array<string, mixed> $block A parameter block whose 'driver' key selects the engine
     * @param LoggerInterface $logger
     *
     * @return DatabaseConnection
     */
    public function connection(string $name, array $block, LoggerInterface $logger = new NullLogger()): DatabaseConnection
    {
        return new DatabaseConnection($this->config($name, $block), $logger);
    }

    /**
     * Build a pool connector from a parameter block.
     *
     * @param string $name The connection name
     * @param array<string, mixed> $block A parameter block whose 'driver' key selects the engine
     * @param LoggerInterface $logger
     *
     * @return DatabaseConnector
     */
    public function connector(string $name, array $block, LoggerInterface $logger = new NullLogger()): DatabaseConnector
    {
        return new DatabaseConnector($this->config($name, $block), $logger);
    }
}

<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database;

use Closure;
use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\Exception\ConnectionException;
use PHPdot\Database\Query\Builder;
use PHPdot\Database\Query\Expression;
use PHPdot\Database\Result\ResultSet;
use PHPdot\Database\Schema\SchemaBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manages multiple named database connections with lazy instantiation.
 *
 * Acts as a registry and facade: connections are created on first access
 * and subsequent calls return the cached instance. All query methods
 * delegate to the default connection.
 */
final class DatabaseManager
{
    /** @var array<string, DatabaseConnection> */
    private array $connections = [];

    /**
     * @param array<string, DatabaseConfig> $configs Named connection configurations
     * @param string $default Default connection name
     * @param LoggerInterface $logger PSR-3 logger instance
     */
    public function __construct(
        private readonly array $configs,
        private string $default = 'default',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Get a connection by name. Creates lazily on first access.
     *
     * @param string $name The connection name (empty string resolves to default)
     * @throws ConnectionException When no configuration exists for the given name
     */
    public function connection(string $name = ''): DatabaseConnection
    {
        $name = $name !== '' ? $name : $this->default;

        if (!isset($this->connections[$name])) {
            if (!isset($this->configs[$name])) {
                throw ConnectionException::connectionFailed($name, $name, "No configuration for connection '{$name}'");
            }

            $this->connections[$name] = new DatabaseConnection($this->configs[$name], $this->logger);
        }

        return $this->connections[$name];
    }

    /**
     * Begin a fluent query against a table on the default connection.
     *
     * @param string $table The table name
     */
    public function table(string $table): Builder
    {
        return $this->connection()->table($table);
    }

    /**
     * Execute a SELECT query on the default connection.
     *
     * @param string $sql The SQL query
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     */
    public function select(string $sql, array $bindings = []): ResultSet
    {
        return $this->connection()->select($sql, $bindings);
    }

    /**
     * Execute an INSERT statement on the default connection.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     */
    public function insert(string $sql, array $bindings = []): bool
    {
        return $this->connection()->insert($sql, $bindings);
    }

    /**
     * Execute an UPDATE statement on the default connection.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->connection()->update($sql, $bindings);
    }

    /**
     * Execute a DELETE statement on the default connection.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     */
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->connection()->delete($sql, $bindings);
    }

    /**
     * Execute a callback within a transaction on the default connection.
     *
     * @template T
     * @param Closure(DatabaseConnection): T $callback
     * @param int $maxRetries Maximum number of attempts (for deadlock retry)
     * @return T
     */
    public function transaction(Closure $callback, int $maxRetries = 1): mixed
    {
        return $this->connection()->transaction($callback, $maxRetries);
    }

    /**
     * Get a SchemaBuilder for the default connection.
     */
    public function schema(): SchemaBuilder
    {
        return $this->connection()->schema();
    }

    /**
     * Create a raw SQL expression.
     *
     * @param string $expression The raw SQL
     */
    public function raw(string $expression): Expression
    {
        return $this->connection()->raw($expression);
    }

    /**
     * Get the name of the default connection.
     */
    public function getDefaultConnection(): string
    {
        return $this->default;
    }

    /**
     * Set the name of the default connection.
     *
     * @param string $name The connection name
     */
    public function setDefaultConnection(string $name): void
    {
        $this->default = $name;
    }

    /**
     * Get all resolved connections.
     *
     * @return array<string, DatabaseConnection>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Disconnect a named connection (or the default).
     *
     * @param string $name The connection name (empty string resolves to default)
     */
    public function disconnect(string $name = ''): void
    {
        $name = $name !== '' ? $name : $this->default;

        if (isset($this->connections[$name])) {
            $this->connections[$name]->close();
            unset($this->connections[$name]);
        }
    }

    /**
     * Disconnect and reconnect a named connection (or the default).
     *
     * @param string $name The connection name (empty string resolves to default)
     * @throws ConnectionException When no configuration exists for the given name
     */
    public function reconnect(string $name = ''): DatabaseConnection
    {
        $this->disconnect($name);

        return $this->connection($name);
    }
}

<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database;

use Closure;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use PDO;
use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\Exception\ConnectionException;
use PHPdot\Database\Exception\DatabaseException;
use PHPdot\Database\Exception\QueryException;
use PHPdot\Database\Query\Builder;
use PHPdot\Database\Query\Expression;
use PHPdot\Database\Query\Grammar\Grammar;
use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPdot\Database\Query\Grammar\PostgresGrammar;
use PHPdot\Database\Query\Grammar\SqliteGrammar;
use PHPdot\Database\Result\ResultSet;
use PHPdot\Database\Schema\Grammar\MySqlSchemaGrammar;
use PHPdot\Database\Schema\Grammar\PostgresSchemaGrammar;
use PHPdot\Database\Schema\Grammar\SqliteSchemaGrammar;
use PHPdot\Database\Schema\SchemaBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Database connection wrapper around Doctrine DBAL.
 *
 * Provides lazy connection, automatic reconnection with exponential backoff,
 * query logging, transaction management with deadlock retry, and raw query methods.
 */
final class DatabaseConnection
{
    private DbalConnection $dbal;

    private bool $connected = false;

    private ?DbalConnection $readDbal = null;

    private bool $readConnected = false;

    private bool $recordsModified = false;

    private bool $forceWriteForNextRead = false;

    private bool $queryLogEnabled = false;

    private int $queryLogMaxEntries = 0;

    /** @var list<array{query: string, bindings: array<int<0, max>|string, mixed>, time: float}> */
    private array $queryLog = [];

    private readonly LoggerInterface $logger;

    private readonly Grammar $grammar;

    /**
     * @param DatabaseConfig $config Database configuration
     * @param LoggerInterface $logger PSR-3 logger instance
     */
    public function __construct(
        private readonly DatabaseConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->grammar = $this->createGrammar();
    }

    /**
     * Create the underlying DBAL connection lazily.
     *
     * @throws ConnectionException When the driver is unsupported or connection fails
     */
    private function connect(): void
    {
        try {
            $this->dbal = match ($this->config->driver) {
                'mysql' => DriverManager::getConnection([
                    'driver' => 'pdo_mysql',
                    'host' => $this->config->host,
                    'port' => $this->config->port,
                    'dbname' => $this->config->database,
                    'user' => $this->config->username,
                    'password' => $this->config->password,
                    'charset' => $this->config->charset,
                    'driverOptions' => $this->config->options,
                ]),
                'pgsql' => DriverManager::getConnection([
                    'driver' => 'pdo_pgsql',
                    'host' => $this->config->host,
                    'port' => $this->config->port,
                    'dbname' => $this->config->database,
                    'user' => $this->config->username,
                    'password' => $this->config->password,
                    'charset' => $this->config->charset,
                    'driverOptions' => $this->config->options,
                ]),
                'sqlite' => DriverManager::getConnection([
                    'driver' => 'pdo_sqlite',
                    'path' => $this->config->database,
                    'driverOptions' => $this->config->options,
                ]),
                default => throw ConnectionException::connectionFailed(
                    $this->config->driver,
                    $this->config->host,
                    'Unsupported driver',
                ),
            };
            $this->connected = true;
        } catch (DbalException $e) {
            throw ConnectionException::connectionFailed(
                $this->config->driver,
                $this->config->host,
                $e->getMessage(),
            );
        }
    }

    /**
     * Ensure the connection is alive, connecting or reconnecting as needed.
     *
     * @throws ConnectionException When unable to establish a connection
     */
    public function ensureConnected(): void
    {
        if (!$this->connected) {
            $this->connect();
        }
    }

    /**
     * Reconnect with exponential backoff.
     *
     * @throws ConnectionException When all retry attempts are exhausted
     */
    public function reconnect(): void
    {
        $this->close();

        $lastError = '';

        for ($attempt = 1; $attempt <= $this->config->maxRetries; $attempt++) {
            try {
                $this->connect();

                return;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();

                if ($attempt < $this->config->maxRetries) {
                    usleep($this->config->retryDelayMs * (2 ** ($attempt - 1)) * 1000);
                }
            }
        }

        throw ConnectionException::reconnectFailed($lastError);
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        if ($this->connected) {
            $this->dbal->close();
            $this->connected = false;
        }

        if ($this->readConnected && $this->readDbal !== null) {
            $this->readDbal->close();
        }

        $this->readDbal = null;
        $this->readConnected = false;
        $this->recordsModified = false;
        $this->forceWriteForNextRead = false;
    }

    /**
     * Check if the connection is alive.
     */
    public function ping(): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $this->dbal->executeQuery('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if the connection is currently established.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }


    /**
     * Execute a SELECT query and return a ResultSet.
     *
     * @param string $sql The SQL query
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @throws QueryException When the query fails
     */
    public function select(string $sql, array $bindings = []): ResultSet
    {
        $start = microtime(true);

        try {
            /** @var ResultSet */
            return $this->runOnRead(function (DbalConnection $conn) use ($sql, $bindings, $start): ResultSet {
                $result = $conn->executeQuery($sql, $bindings);
                /** @var list<array<string, mixed>> $rows */
                $rows = $result->fetchAllAssociative();
                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, $bindings, $time);

                return new ResultSet($rows);
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, $bindings, $e->getMessage());
        }
    }

    /**
     * Execute a SELECT query and return the first row.
     *
     * @param string $sql The SQL query
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @throws QueryException When the query fails
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->select($sql, $bindings)->first();
    }

    /**
     * Execute an INSERT statement and return true on success.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @throws QueryException When the statement fails
     */
    public function insert(string $sql, array $bindings = []): bool
    {
        return $this->affectingStatement($sql, $bindings) >= 0;
    }

    /**
     * Execute an UPDATE statement and return the number of affected rows.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @throws QueryException When the statement fails
     */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->affectingStatement($sql, $bindings);
    }

    /**
     * Execute a DELETE statement and return the number of affected rows.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @throws QueryException When the statement fails
     */
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->affectingStatement($sql, $bindings);
    }

    /**
     * Execute a generic SQL statement that returns true/false.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @throws QueryException When the statement fails
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $start = microtime(true);

        try {
            /** @var bool */
            return $this->runOnWrite(function (DbalConnection $conn) use ($sql, $bindings, $start): bool {
                $conn->executeStatement($sql, $bindings);
                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, $bindings, $time);

                return true;
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, $bindings, $e->getMessage());
        }
    }

    /**
     * Execute a SQL statement and return the number of affected rows.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @throws QueryException When the statement fails
     */
    public function affectingStatement(string $sql, array $bindings = []): int
    {
        $start = microtime(true);

        try {
            /** @var int */
            return $this->runOnWrite(function (DbalConnection $conn) use ($sql, $bindings, $start): int {
                $affected = $conn->executeStatement($sql, $bindings);
                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, $bindings, $time);

                return (int) $affected;
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, $bindings, $e->getMessage());
        }
    }

    /**
     * Execute a raw, unprepared SQL statement.
     *
     * WARNING: This method does NOT use parameter binding. Do NOT pass user input.
     *
     * @param string $sql The raw SQL statement
     * @throws QueryException When the statement fails
     */
    public function unprepared(string $sql): bool
    {
        $start = microtime(true);

        try {
            /** @var bool */
            return $this->runOnWrite(function (DbalConnection $conn) use ($sql, $start): bool {
                $conn->executeStatement($sql);
                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, [], $time);

                return true;
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, [], $e->getMessage());
        }
    }


    /**
     * Execute a callback within a transaction, with optional deadlock retry.
     *
     * @template T
     * @param Closure(DatabaseConnection): T $callback
     * @param int $maxRetries Maximum number of attempts (for deadlock retry)
     * @throws Throwable When the transaction fails after all retries
     * @return T
     */
    public function transaction(Closure $callback, int $maxRetries = 1): mixed
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
                $this->commit();

                return $result;
            } catch (Throwable $e) {
                $this->rollBack();

                if ($this->isDeadlock($e) && $attempt < $maxRetries) {
                    usleep($this->config->retryDelayMs * (2 ** ($attempt - 1)) * 1000);

                    continue;
                }

                throw $e;
            }
        }

        throw new DatabaseException('Transaction failed after max retries');
    }

    /**
     * Start a new database transaction.
     *
     * @throws ConnectionException When the connection is not available
     */
    public function beginTransaction(): void
    {
        $this->ensureConnected();

        try {
            $this->dbal->beginTransaction();
        } catch (DbalException $e) {
            throw ConnectionException::disconnected($e->getMessage());
        }
    }

    /**
     * Commit the active transaction.
     *
     * @throws ConnectionException When the commit fails
     */
    public function commit(): void
    {
        try {
            $this->dbal->commit();
        } catch (DbalException $e) {
            throw ConnectionException::disconnected($e->getMessage());
        }
    }

    /**
     * Roll back the active transaction.
     *
     * @throws ConnectionException When the rollback fails
     */
    public function rollBack(): void
    {
        try {
            $this->dbal->rollBack();
        } catch (DbalException $e) {
            throw ConnectionException::disconnected($e->getMessage());
        }
    }

    /**
     * Get the current transaction nesting level.
     */
    public function transactionLevel(): int
    {
        if (!$this->connected) {
            return 0;
        }

        return $this->dbal->getTransactionNestingLevel();
    }


    /**
     * Enable query logging.
     *
     * @param int $maxEntries Maximum log entries (0 = unlimited)
     */
    public function enableQueryLog(int $maxEntries = 0): void
    {
        $this->queryLogEnabled = true;
        $this->queryLogMaxEntries = $maxEntries;
    }

    /**
     * Disable query logging.
     */
    public function disableQueryLog(): void
    {
        $this->queryLogEnabled = false;
    }

    /**
     * Flush and return the query log.
     *
     * @return list<array{query: string, bindings: array<int<0, max>|string, mixed>, time: float}>
     */
    public function flushQueryLog(): array
    {
        $log = $this->queryLog;
        $this->queryLog = [];

        return $log;
    }

    /**
     * Get the current query log without flushing.
     *
     * @return list<array{query: string, bindings: array<int<0, max>|string, mixed>, time: float}>
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }


    /**
     * Get the underlying Doctrine DBAL connection.
     *
     * @throws ConnectionException When not connected
     */
    public function getDbal(): DbalConnection
    {
        $this->ensureConnected();

        return $this->dbal;
    }

    /**
     * Get the underlying PDO instance.
     *
     * @throws ConnectionException When the native connection is not PDO
     */
    public function getPdo(): PDO
    {
        $this->ensureConnected();

        $native = $this->dbal->getNativeConnection();

        if (!$native instanceof PDO) {
            throw ConnectionException::disconnected('Native connection is not PDO');
        }

        return $native;
    }


    /**
     * Get the configured driver name.
     */
    public function getDriverName(): string
    {
        return $this->config->driver;
    }

    /**
     * Get the configured database name.
     */
    public function getDatabaseName(): string
    {
        return $this->config->database;
    }

    /**
     * Get the configured table prefix.
     */
    public function getTablePrefix(): string
    {
        return $this->config->prefix;
    }


    /**
     * Create a raw SQL expression.
     *
     * @param string $expression The raw SQL
     */
    public function raw(string $expression): Expression
    {
        return new Expression($expression);
    }

    /**
     * Begin a fluent query against a database table.
     *
     * @param string $table The table name
     */
    public function table(string $table): Builder
    {
        $this->ensureConnected();
        $builder = new Builder($this, $this->grammar);

        return $builder->from($table);
    }

    /**
     * Get the Grammar instance for this connection.
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Get a SchemaBuilder instance for DDL operations.
     */
    public function schema(): SchemaBuilder
    {
        $schemaGrammar = match ($this->config->driver) {
            'mysql' => new MySqlSchemaGrammar(),
            'pgsql' => new PostgresSchemaGrammar(),
            'sqlite' => new SqliteSchemaGrammar(),
            default => new MySqlSchemaGrammar(),
        };
        $schemaGrammar->setTablePrefix($this->config->prefix);

        return new SchemaBuilder($this, $schemaGrammar);
    }


    /**
     * Get the appropriate DBAL connection for read queries.
     *
     * Returns the read replica when configured, unless:
     * - No replicas are configured
     * - The next read is explicitly forced to write via forceWriteConnection()
     * - Sticky mode is active and records have been modified
     * - A transaction is in progress on the write connection
     */
    private function getReadConnection(): DbalConnection
    {
        if ($this->forceWriteForNextRead) {
            $this->forceWriteForNextRead = false;
            $this->ensureConnected();

            return $this->dbal;
        }

        if ($this->config->read === []) {
            $this->ensureConnected();

            return $this->dbal;
        }

        if ($this->config->sticky && $this->recordsModified) {
            $this->ensureConnected();

            return $this->dbal;
        }

        if ($this->connected && $this->dbal->getTransactionNestingLevel() > 0) {
            return $this->dbal;
        }

        $this->ensureReadConnected();

        if ($this->readDbal === null) {
            $this->ensureConnected();

            return $this->dbal;
        }

        return $this->readDbal;
    }

    /**
     * Force the next read query to use the write connection.
     *
     * This flag is automatically reset after the next read.
     */
    public function forceWriteConnection(): void
    {
        $this->forceWriteForNextRead = true;
    }

    /**
     * Check whether records have been modified on this connection.
     */
    public function hasModifiedRecords(): bool
    {
        return $this->recordsModified;
    }

    /**
     * Ensure the read replica connection is alive, connecting as needed.
     */
    private function ensureReadConnected(): void
    {
        if ($this->readConnected && $this->readDbal !== null) {
            return;
        }

        $this->connectRead();
    }

    /**
     * Create the read replica DBAL connection.
     *
     * Picks a random replica from the configured list. Falls back to the
     * primary connection on failure.
     */
    private function connectRead(): void
    {
        if ($this->config->read === []) {
            return;
        }

        /** @var array<string, mixed> $replicaConfig */
        $replicaConfig = $this->config->read[array_rand($this->config->read)];

        $host = isset($replicaConfig['host']) && is_string($replicaConfig['host'])
            ? $replicaConfig['host'] : $this->config->host;
        $port = isset($replicaConfig['port']) && is_int($replicaConfig['port'])
            ? $replicaConfig['port'] : $this->config->port;
        $username = isset($replicaConfig['username']) && is_string($replicaConfig['username'])
            ? $replicaConfig['username'] : $this->config->username;
        $password = isset($replicaConfig['password']) && is_string($replicaConfig['password'])
            ? $replicaConfig['password'] : $this->config->password;
        $database = isset($replicaConfig['database']) && is_string($replicaConfig['database'])
            ? $replicaConfig['database'] : $this->config->database;

        try {
            $this->readDbal = match ($this->config->driver) {
                'mysql' => DriverManager::getConnection([
                    'driver' => 'pdo_mysql',
                    'host' => $host,
                    'port' => $port,
                    'dbname' => $database,
                    'user' => $username,
                    'password' => $password,
                    'charset' => $this->config->charset,
                    'driverOptions' => $this->config->options,
                ]),
                'pgsql' => DriverManager::getConnection([
                    'driver' => 'pdo_pgsql',
                    'host' => $host,
                    'port' => $port,
                    'dbname' => $database,
                    'user' => $username,
                    'password' => $password,
                    'charset' => $this->config->charset,
                    'driverOptions' => $this->config->options,
                ]),
                default => throw ConnectionException::connectionFailed(
                    $this->config->driver,
                    $host,
                    'Read replicas not supported for this driver',
                ),
            };
            $this->readConnected = true;
        } catch (DbalException $e) {
            $this->logger->warning('Read replica connection failed, falling back to primary', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
            $this->readDbal = null;
            $this->readConnected = false;
        }
    }

    /**
     * Execute a callback on the read connection with automatic reconnection.
     *
     * @template T
     * @param Closure(DbalConnection): T $callback
     * @throws DbalException When the query fails for non-connection reasons
     * @return T
     */
    private function runOnRead(Closure $callback): mixed
    {
        $connection = $this->getReadConnection();

        try {
            return $callback($connection);
        } catch (DbalException $e) {
            if ($this->isConnectionLost($e)) {
                if ($this->config->read !== [] && $connection === $this->readDbal) {
                    $this->readConnected = false;
                    $this->readDbal = null;
                    $this->connectRead();

                    if ($this->readDbal !== null) {
                        return $callback($this->readDbal);
                    }
                }

                $this->reconnect();

                return $callback($this->dbal);
            }

            throw $e;
        }
    }

    /**
     * Execute a callback on the write connection with automatic reconnection.
     *
     * Sets the recordsModified flag on success.
     *
     * @template T
     * @param Closure(DbalConnection): T $callback
     * @throws DbalException When the query fails for non-connection reasons
     * @return T
     */
    private function runOnWrite(Closure $callback): mixed
    {
        $this->ensureConnected();

        try {
            $result = $callback($this->dbal);
            $this->recordsModified = true;

            return $result;
        } catch (DbalException $e) {
            if ($this->isConnectionLost($e)) {
                $this->reconnect();
                $result = $callback($this->dbal);
                $this->recordsModified = true;

                return $result;
            }

            throw $e;
        }
    }


    /**
     * Determine if the given exception indicates a lost connection.
     *
     * @param Throwable $e The exception to inspect
     */
    private function isConnectionLost(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'broken pipe')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'no connection');
    }

    /**
     * Log a query execution.
     *
     * @param string $sql The SQL query
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @param float $timeMs Execution time in milliseconds
     */
    private function logQuery(string $sql, array $bindings, float $timeMs): void
    {
        if ($this->queryLogEnabled) {
            $this->queryLog[] = ['query' => $sql, 'bindings' => $bindings, 'time' => $timeMs];

            if ($this->queryLogMaxEntries > 0 && count($this->queryLog) > $this->queryLogMaxEntries) {
                array_shift($this->queryLog);
            }
        }

        $this->logger->debug('Query executed', [
            'query' => $sql,
            'bindings' => $bindings,
            'time_ms' => $timeMs,
        ]);

        if ($timeMs > $this->config->slowQueryThreshold) {
            $this->logger->warning('Slow query detected', [
                'query' => $sql,
                'time_ms' => $timeMs,
            ]);
        }
    }

    /**
     * Create the appropriate Grammar instance for the configured driver.
     */
    private function createGrammar(): Grammar
    {
        $grammar = match ($this->config->driver) {
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            'sqlite' => new SqliteGrammar(),
            default => new MySqlGrammar(),
        };
        $grammar->setTablePrefix($this->config->prefix);

        return $grammar;
    }

    /**
     * Determine if the given exception was caused by a deadlock.
     */
    private function isDeadlock(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '1213')
            || str_contains($message, '40001')
            || str_contains($message, 'deadlock');
    }
}

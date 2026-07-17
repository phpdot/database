<?php

declare(strict_types=1);

/**
 * Database connection wrapper around Doctrine DBAL.
 *
 * Provides lazy connection, automatic reconnection with exponential backoff,
 * query logging, transaction management with deadlock retry, and raw query methods.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database;

use Closure;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\Exception as DbalDriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\ParameterType;
use PDO;
use PHPdot\Database\Connection\ConnectionConfig;
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

    private bool $pretending = false;

    /**
     * @var list<string>
     */
    private array $pretendQueries = [];

    /**
     * @var list<array{query: string, bindings: array<int<0, max>|string, mixed>, time: float}>
     */
    private array $queryLog = [];

    private readonly LoggerInterface $logger;

    private readonly Grammar $grammar;

    /**
     * Open a lazy connection for the given driver configuration.
     *
     * @param ConnectionConfig $config Database configuration
     * @param LoggerInterface $logger PSR-3 logger instance
     */
    public function __construct(
        private readonly ConnectionConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->grammar = $this->createGrammar();
    }

    /**
     * Create the underlying DBAL connection lazily.
     *
     * @throws ConnectionException When the driver is unsupported or connection fails
     *
     * @return void
     */
    private function connect(): void
    {
        try {
            $this->dbal = DriverManager::getConnection($this->config->dbalParams());
            $this->dbal->getNativeConnection();
            $this->connected = true;

            if ($this->config->driver() === 'sqlite') {
                $this->dbal->executeStatement('PRAGMA foreign_keys = ON');
            }
        } catch (DbalException $e) {
            throw ConnectionException::connectionFailed(
                $this->config->driver(),
                $this->config->database(),
                $e->getMessage(),
            );
        }
    }

    /**
     * Ensure the connection is alive, connecting or reconnecting as needed.
     *
     * @throws ConnectionException When unable to establish a connection
     *
     * @return void
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
     *
     * @return void
     */
    public function reconnect(): void
    {
        $recordsModified = $this->recordsModified;
        $this->close();

        $lastError = '';

        for ($attempt = 1; $attempt <= $this->config->options()->maxRetries; $attempt++) {
            try {
                $this->connect();
                $this->recordsModified = $recordsModified;

                return;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();

                if ($attempt < $this->config->options()->maxRetries) {
                    usleep($this->config->options()->retryDelayMs * (2 ** ($attempt - 1)) * 1000);
                }
            }
        }

        throw ConnectionException::reconnectFailed($lastError);
    }

    /**
     * Close the connection.
     *
     * @return void
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
     * Reset per-borrow state so the connection can be safely reused by the next
     * coroutine that borrows it from a pool.
     *
     * Rolls back any transaction left open (which would otherwise leak into and
     * be committed by the next borrower), clears read/write-split stickiness and
     * the force-write flag, and flushes/disables the query log (so one request's
     * SQL and bound values never leak into another's, and the buffer cannot grow
     * unbounded). Unlike close(), the underlying socket stays open.
     *
     * @return void
     */
    public function reset(): void
    {
        try {
            while ($this->transactionLevel() > 0) {
                $this->rollBack();
            }
        } catch (Throwable) {
        }

        $this->recordsModified = false;
        $this->forceWriteForNextRead = false;
        $this->queryLogEnabled = false;
        $this->queryLog = [];
    }

    /**
     * Check if the connection is alive.
     *
     * @return bool
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
     *
     * @return bool
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
     *
     * @throws QueryException When the query fails
     *
     * @return ResultSet
     */
    public function select(string $sql, array $bindings = []): ResultSet
    {
        $start = microtime(true);

        try {
            /**
             * @var ResultSet
             */
            return $this->runOnRead(function (DbalConnection $conn) use ($sql, $bindings, $start): ResultSet {
                $result = $conn->executeQuery($sql, $bindings, $this->paramTypes($bindings));
                /**
                 * @var list<array<string, mixed>> $rows
                 */
                $rows = $result->fetchAllAssociative();
                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, $bindings, $time);

                return new ResultSet($rows);
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, $bindings, $e->getMessage(), $e);
        }
    }

    /**
     * Execute a SELECT query and return the first row.
     *
     * @param string $sql The SQL query
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     *
     * @throws QueryException When the query fails
     *
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
     *
     * @throws QueryException When the statement fails
     *
     * @return bool
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
     *
     * @throws QueryException When the statement fails
     *
     * @return int
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
     *
     * @throws QueryException When the statement fails
     *
     * @return int
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
     *
     * @throws QueryException When the statement fails
     *
     * @return bool
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        if ($this->pretending) {
            $this->pretendQueries[] = $sql;

            return true;
        }

        $start = microtime(true);

        try {
            /**
             * @var bool
             */
            return $this->runOnWrite(function (DbalConnection $conn) use ($sql, $bindings, $start): bool {
                $conn->executeStatement($sql, $bindings, $this->paramTypes($bindings));
                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, $bindings, $time);

                return true;
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, $bindings, $e->getMessage(), $e);
        }
    }

    /**
     * Execute a SQL statement and return the number of affected rows.
     *
     * @param string $sql The SQL statement
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     *
     * @throws QueryException When the statement fails
     *
     * @return int
     */
    public function affectingStatement(string $sql, array $bindings = []): int
    {
        if ($this->pretending) {
            $this->pretendQueries[] = $sql;

            return 0;
        }

        $start = microtime(true);

        try {
            /**
             * @var int
             */
            return $this->runOnWrite(function (DbalConnection $conn) use ($sql, $bindings, $start): int {
                $affected = $conn->executeStatement($sql, $bindings, $this->paramTypes($bindings));
                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, $bindings, $time);

                return (int) $affected;
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, $bindings, $e->getMessage(), $e);
        }
    }

    /**
     * Execute a raw, unprepared SQL statement.
     *
     * WARNING: This method does NOT use parameter binding. Do NOT pass user input.
     *
     * @param string $sql The raw SQL statement
     *
     * @throws QueryException When the statement fails
     *
     * @return bool
     */
    public function unprepared(string $sql): bool
    {
        if ($this->pretending) {
            $this->pretendQueries[] = $sql;

            return true;
        }

        $start = microtime(true);

        try {
            /**
             * @var bool
             */
            return $this->runOnWrite(function (DbalConnection $conn) use ($sql, $start): bool {
                $conn->executeStatement($sql);
                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, [], $time);

                return true;
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, [], $e->getMessage(), $e);
        }
    }

    /**
     * Execute an INSERT that yields a generated id, routed through the write path.
     *
     * Uses the RETURNING value when the compiled SQL provides one (Postgres,
     * SQLite); otherwise falls back to the driver's lastInsertId(). Running on
     * the write connection ensures read-after-write stickiness, reconnection,
     * and query logging all apply — unlike a raw PDO call.
     *
     * @param string $sql The compiled INSERT statement (possibly with RETURNING)
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @param string $sequence The generated column name (used to read RETURNING)
     *
     * @throws QueryException When the statement fails
     *
     * @return int
     */
    public function insertGetId(string $sql, array $bindings = [], string $sequence = 'id'): int
    {
        if ($this->pretending) {
            $this->pretendQueries[] = $sql;

            return 0;
        }

        $start = microtime(true);

        try {
            /**
             * @var int
             */
            return $this->runOnWrite(function (DbalConnection $conn) use ($sql, $bindings, $sequence, $start): int {
                if (stripos($sql, ' returning ') !== false) {
                    $row = $conn->executeQuery($sql, $bindings, $this->paramTypes($bindings))->fetchAssociative();
                    $id = is_array($row)
                        ? (array_key_exists($sequence, $row) ? $row[$sequence] : reset($row))
                        : null;
                } else {
                    $conn->executeStatement($sql, $bindings, $this->paramTypes($bindings));
                    $native = $conn->getNativeConnection();
                    $id = $native instanceof PDO ? $native->lastInsertId() : null;
                }

                $time = (microtime(true) - $start) * 1000;
                $this->logQuery($sql, $bindings, $time);

                return is_numeric($id) ? (int) $id : 0;
            });
        } catch (DbalException $e) {
            throw QueryException::executionFailed($sql, $bindings, $e->getMessage(), $e);
        }
    }

    /**
     * Execute a callback within a transaction, with optional deadlock retry.
     *
     * Deadlock retry only applies to a top-level transaction. When called
     * inside an existing transaction the callback runs in a savepoint, and a
     * deadlock rolls back the entire outer transaction, so retrying the inner
     * block would be unsound — maxRetries is forced to 1 in that case.
     *
     * @template T
     *
     * @param \Closure(DatabaseConnection): T $callback
     * @param int $maxRetries Maximum number of attempts (deadlock retry, top-level only)
     *
     * @throws \Throwable When the transaction fails after all retries
     *
     * @return T
     */
    public function transaction(Closure $callback, int $maxRetries = 1): mixed
    {
        if ($this->transactionLevel() > 0) {
            $maxRetries = 1;
        }

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
                $this->commit();

                return $result;
            } catch (Throwable $e) {
                $this->rollBack();

                if ($this->isDeadlock($e) && $attempt < $maxRetries) {
                    usleep($this->config->options()->retryDelayMs * (2 ** ($attempt - 1)) * 1000);

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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return int
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
     *
     * @return void
     */
    public function enableQueryLog(int $maxEntries = 0): void
    {
        $this->queryLogEnabled = true;
        $this->queryLogMaxEntries = $maxEntries;
    }

    /**
     * Disable query logging.
     *
     * @return void
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
     * Run a callback in "pretend" (dry-run) mode.
     *
     * Write statements executed during the callback are recorded and their SQL
     * returned, but never sent to the database. Read queries still execute.
     * This deliberately does NOT rely on a transaction rollback, because MySQL
     * implicitly commits DDL — so a transactional dry-run would both fail and
     * leave the schema changed.
     *
     * @param \Closure(): void $callback The operations to preview
     *
     * @return list<string> The SQL that would have been executed
     */
    public function pretend(Closure $callback): array
    {
        $previous = $this->pretending;
        $this->pretending = true;
        $this->pretendQueries = [];

        try {
            $callback();
        } finally {
            $this->pretending = $previous;
        }

        $queries = $this->pretendQueries;
        $this->pretendQueries = [];

        return $queries;
    }

    /**
     * Get the underlying Doctrine DBAL connection.
     *
     * @throws ConnectionException When not connected
     *
     * @return DbalConnection
     */
    public function getDbal(): DbalConnection
    {
        $this->ensureConnected();

        return $this->dbal;
    }

    /**
     * Get the underlying PDO instance.
     *
     * @throws ConnectionException When the native connection is not \PDO
     *
     * @return \PDO
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
     *
     * @return string
     */
    public function getDriverName(): string
    {
        return $this->config->driver();
    }

    /**
     * Get the configured database name.
     *
     * @return string
     */
    public function getDatabaseName(): string
    {
        return $this->config->database();
    }

    /**
     * Get the configured table prefix.
     *
     * @return string
     */
    public function getTablePrefix(): string
    {
        return $this->config->options()->prefix;
    }

    /**
     * Create a raw SQL expression.
     *
     * @param string $expression The raw SQL
     *
     * @return Expression
     */
    public function raw(string $expression): Expression
    {
        return new Expression($expression);
    }

    /**
     * Begin a fluent query against a database table.
     *
     * @param string $table The table name
     *
     * @return Builder
     */
    public function table(string $table): Builder
    {
        $this->ensureConnected();
        $builder = new Builder($this, $this->grammar);

        return $builder->from($table);
    }

    /**
     * Get the Grammar instance for this connection.
     *
     * @return Grammar
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Get a SchemaBuilder instance for DDL operations.
     *
     * @return SchemaBuilder
     */
    public function schema(): SchemaBuilder
    {
        $schemaGrammar = match ($this->config->driver()) {
            'mysql' => new MySqlSchemaGrammar(),
            'pgsql' => new PostgresSchemaGrammar(),
            'sqlite' => new SqliteSchemaGrammar(),
            default => new MySqlSchemaGrammar(),
        };
        $schemaGrammar->setTablePrefix($this->config->options()->prefix);

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
     *
     * @return DbalConnection
     */
    private function getReadConnection(): DbalConnection
    {
        if ($this->forceWriteForNextRead) {
            $this->forceWriteForNextRead = false;
            $this->ensureConnected();

            return $this->dbal;
        }

        if ($this->config->options()->read === []) {
            $this->ensureConnected();

            return $this->dbal;
        }

        if ($this->config->options()->sticky && $this->recordsModified) {
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
     *
     * @return void
     */
    public function forceWriteConnection(): void
    {
        $this->forceWriteForNextRead = true;
    }

    /**
     * Check whether records have been modified on this connection.
     *
     * @return bool
     */
    public function hasModifiedRecords(): bool
    {
        return $this->recordsModified;
    }

    /**
     * Ensure the read replica connection is alive, connecting as needed.
     *
     * @return void
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
     *
     * @return void
     */
    private function connectRead(): void
    {
        $params = $this->config->readReplicaParams();

        if ($params === null) {
            return;
        }

        try {
            $this->readDbal = DriverManager::getConnection($params);
            $this->readDbal->getNativeConnection();
            $this->readConnected = true;
        } catch (DbalException $e) {
            $this->logger->warning('Read replica connection failed, falling back to primary', [
                'host' => $params['host'] ?? '',
                'error' => $e->getMessage(),
            ]);
            $this->readDbal = null;
            $this->readConnected = false;
        }
    }

    /**
     * Execute a callback on the read connection with automatic reconnection.
     *
     * A lost replica is retried on a freshly picked replica, then falls back
     * to the primary. The primary itself is never reconnected mid-transaction
     * (see runOnWrite); the exception propagates so the transaction fails
     * atomically.
     *
     * @template T
     *
     * @param \Closure(DbalConnection): T $callback
     *
     * @throws DbalException When the query fails, or the connection is lost mid-transaction
     *
     * @return T
     */
    private function runOnRead(Closure $callback): mixed
    {
        $connection = $this->getReadConnection();
        $inTransaction = $this->transactionLevel() > 0;

        try {
            return $callback($connection);
        } catch (DbalException $e) {
            if ($this->isConnectionLost($e)) {
                if (!$inTransaction && $this->config->options()->read !== [] && $connection === $this->readDbal) {
                    $this->readConnected = false;
                    $this->readDbal = null;
                    $this->connectRead();

                    if ($this->readDbal !== null) {
                        return $callback($this->readDbal);
                    }
                }

                if ($inTransaction) {
                    throw $e;
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
     * Sets the recordsModified flag on success. When the connection is lost
     * inside an open transaction, the statement is never retried: reconnecting
     * would silently re-execute it in autocommit mode outside the transaction,
     * committing a partial write while the transaction appears to roll back.
     * The exception propagates so the transaction fails atomically.
     *
     * @template T
     *
     * @param \Closure(DbalConnection): T $callback
     *
     * @throws DbalException When the query fails, or the connection is lost mid-transaction
     *
     * @return T
     */
    private function runOnWrite(Closure $callback): mixed
    {
        $this->ensureConnected();
        $inTransaction = $this->transactionLevel() > 0;

        try {
            $result = $callback($this->dbal);
            $this->recordsModified = true;

            return $result;
        } catch (DbalException $e) {
            if ($this->isConnectionLost($e) && !$inTransaction) {
                $this->reconnect();
                $result = $callback($this->dbal);
                $this->recordsModified = true;

                return $result;
            }

            throw $e;
        }
    }

    /**
     * Infer DBAL parameter types from the PHP binding values.
     *
     * Without this, all bindings are sent as strings, which breaks SQLite
     * comparisons against affinity-less operands — e.g. an integer bound as a
     * string makes `json_array_length(col) = '2'` never match, and a recursive
     * CTE's `n < '5'` is always true (SQLite sorts every integer before any
     * text), causing an infinite loop. Typing integers as integers fixes this
     * without changing behaviour for ordinary typed columns on any driver.
     *
     * @param array<int<0, max>|string, mixed> $bindings The parameter bindings
     *
     * @return array<int<0, max>|string, ParameterType>
     */
    private function paramTypes(array $bindings): array
    {
        $types = [];

        foreach ($bindings as $key => $value) {
            $types[$key] = match (true) {
                is_int($value) => ParameterType::INTEGER,
                is_bool($value) => ParameterType::BOOLEAN,
                $value === null => ParameterType::NULL,
                default => ParameterType::STRING,
            };
        }

        return $types;
    }

    /**
     * Determine if the given exception indicates a lost connection.
     *
     * Detection is tiered from most to least reliable: DBAL's typed
     * ConnectionException hierarchy first (the drivers' own error-code
     * mapping), then the SQLSTATE connection-exception class (08xxx, plus
     * 57P01 admin shutdown) from the driver exception chain, and finally a
     * message-substring fallback for errors the driver converters leave
     * generic (e.g. PostgreSQL's "server closed the connection unexpectedly").
     *
     * @param \Throwable $e The exception to inspect
     *
     * @return bool
     */
    private function isConnectionLost(Throwable $e): bool
    {
        if ($e instanceof DbalConnectionException) {
            return true;
        }

        for ($prev = $e; $prev !== null; $prev = $prev->getPrevious()) {
            if ($prev instanceof DbalDriverException) {
                $state = $prev->getSQLState();

                if ($state !== null && (str_starts_with($state, '08') || $state === '57P01')) {
                    return true;
                }
            }
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'broken pipe')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'no connection')
            || str_contains($message, 'server closed the connection')
            || str_contains($message, 'terminating connection')
            || str_contains($message, 'ssl connection has been closed');
    }

    /**
     * Log a query execution.
     *
     * @param string $sql The SQL query
     * @param array<int<0, max>|string, mixed> $bindings Parameter bindings
     * @param float $timeMs Execution time in milliseconds
     *
     * @return void
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

        if ($timeMs > $this->config->options()->slowQueryThreshold) {
            $this->logger->warning('Slow query detected', [
                'query' => $sql,
                'time_ms' => $timeMs,
            ]);
        }
    }

    /**
     * Create the appropriate Grammar instance for the configured driver.
     *
     * @return Grammar
     */
    private function createGrammar(): Grammar
    {
        $grammar = match ($this->config->driver()) {
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            'sqlite' => new SqliteGrammar(),
            default => new MySqlGrammar(),
        };
        $grammar->setTablePrefix($this->config->options()->prefix);

        return $grammar;
    }

    /**
     * Determine if the given exception was caused by a deadlock.
     *
     * @param \Throwable $e
     *
     * @return bool
     */
    private function isDeadlock(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '1213')
            || str_contains($message, '40001')
            || str_contains($message, 'deadlock');
    }
}

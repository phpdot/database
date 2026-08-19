<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit;

use Doctrine\DBAL\Driver\PDO\Exception as DbalPdoException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverExceptionWrapper;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PDOException;
use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\Exception\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * How a deadlock is RECOGNISED — the decision that governs whether a
 * top-level transaction is retried or its failure surfaces to the caller.
 *
 * Same standard as connection-loss detection: every tier reads a value the
 * driver assigns (Doctrine's RetryableException classification, then the
 * serialization SQLSTATEs), and the last test fails if message matching ever
 * returns — a false positive here re-runs a transaction that must not be
 * re-run.
 */
final class DeadlockDetectionTest extends TestCase
{
    /**
     * Build a driver-level exception carrying a SQLSTATE and driver code, the
     * way DBAL wraps one coming out of PDO.
     *
     * @param string $message
     * @param ?string $sqlState
     * @param int $driverCode
     *
     * @return DbalPdoException
     */
    private static function driverException(string $message, null|string $sqlState, int $driverCode): DbalPdoException
    {
        $pdo = new PDOException($message);
        $pdo->errorInfo = [$sqlState, $driverCode, $message];

        return DbalPdoException::new($pdo);
    }

    /**
     * Invoke the private classifier directly — it is the whole retry
     * decision, so it gets tested without six layers of driver behaviour.
     *
     * @param Throwable $exception
     *
     * @return bool
     */
    private static function classify(Throwable $exception): bool
    {
        $connection = new DatabaseConnection(new MySqlConfig(database: 'unused'));
        $method = new ReflectionMethod($connection, 'isDeadlock');

        return (bool) $method->invoke($connection, $exception);
    }

    /**
     * Tier 1 — Doctrine's RetryableException marker, the way its MySQL
     * converter classifies error 1213.
     */
    #[Test]
    public function typedDeadlockIsRecognised(): void
    {
        $inner = self::driverException('Deadlock found when trying to get lock', '40001', 1213);

        self::assertTrue(self::classify(new DeadlockException($inner, null)));
    }

    /**
     * Tier 1 — lock wait timeout carries the same marker and is equally
     * winnable on a fresh attempt.
     */
    #[Test]
    public function lockWaitTimeoutIsRecognised(): void
    {
        $inner = self::driverException('Lock wait timeout exceeded', 'HY000', 1205);

        self::assertTrue(self::classify(new LockWaitTimeoutException($inner, null)));
    }

    /**
     * The classifier walks the previous chain: transaction callbacks surface
     * failures wrapped in the package's own QueryException.
     */
    #[Test]
    public function wrappedDeadlockIsRecognised(): void
    {
        $inner = self::driverException('Deadlock found when trying to get lock', '40001', 1213);
        $wrapped = new QueryException(
            'UPDATE t SET v = ?',
            [1],
            'Query failed',
            new DeadlockException($inner, null),
        );

        self::assertTrue(self::classify($wrapped));
    }

    /**
     * Tier 2 — the serialization SQLSTATEs, for driver exceptions DBAL
     * leaves unclassified.
     *
     * @param string $sqlState
     */
    #[DataProvider('deadlockSqlStates')]
    #[Test]
    public function deadlockSqlStatesAreRecognised(string $sqlState): void
    {
        $inner = self::driverException('could not serialize access', $sqlState, 7);

        self::assertTrue(self::classify(new DbalDriverExceptionWrapper($inner, null)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deadlockSqlStates(): iterable
    {
        yield 'serialization failure' => ['40001'];
        yield 'deadlock detected (postgres)' => ['40P01'];
    }

    /**
     * Message matching must never return: an exception whose text mentions a
     * deadlock or spells out 1213/40001 is not a deadlock unless the driver
     * classified it as one — retrying it would re-run a failed transaction.
     */
    #[Test]
    public function messagesAloneAreNeverEnough(): void
    {
        self::assertFalse(self::classify(new RuntimeException('deadlock while doing something unrelated')));
        self::assertFalse(self::classify(new RuntimeException('order 1213 failed with code 40001')));

        $inner = self::driverException('Base table or view not found: deadlock_log', '42S02', 1146);
        self::assertFalse(self::classify(new TableNotFoundException($inner, null)));
    }
}

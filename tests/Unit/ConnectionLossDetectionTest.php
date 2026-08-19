<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit;

use Doctrine\DBAL\Driver\PDO\Exception as DbalPdoException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException as DbalDriverExceptionWrapper;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PDOException;
use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * How a lost connection is RECOGNISED — the decision that governs whether a
 * statement is retried on a fresh connection or allowed to fail.
 *
 * Every tier reads a value the driver assigns: Doctrine's typed hierarchy
 * (which its converters populate from driver codes), the SQLSTATE class, and
 * the short list of driver codes Doctrine does not map. Getting this wrong is
 * expensive in both directions — a miss surfaces an error to a user, a false
 * positive retries a statement that should not be retried — so each tier is
 * pinned separately, and the last test fails if message matching ever returns.
 */
final class ConnectionLossDetectionTest extends TestCase
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
        // Doctrine reads errorInfo alone: [SQLSTATE, driver code, message].
        $pdo = new PDOException($message);
        $pdo->errorInfo = [$sqlState, $driverCode, $message];

        return DbalPdoException::new($pdo);
    }

    /**
     * Invoke the private classifier — it is deliberately not public API, but
     * it is the whole recovery decision, so it gets tested directly rather
     * than through six layers of driver behaviour.
     *
     * @param Throwable $exception
     *
     * @return bool
     */
    private static function classify(Throwable $exception): bool
    {
        $connection = new DatabaseConnection(new MySqlConfig(database: 'unused'));
        $method = new ReflectionMethod($connection, 'isConnectionLost');

        return (bool) $method->invoke($connection, $exception);
    }

    /**
     * Tier 1 — Doctrine's typed hierarchy. Its MySQL converter maps 2006 and
     * 4031 to ConnectionLost, which is the case a real dead socket produces.
     */
    #[Test]
    public function typedConnectionLostIsRecognised(): void
    {
        $inner = self::driverException('MySQL server has gone away', 'HY000', 2006);

        self::assertTrue(self::classify(new ConnectionLost($inner, null)));
    }

    /**
     * Tier 2 — SQLSTATE. Class 08 is the ANSI "connection exception" family;
     * 57P01 is PostgreSQL's admin shutdown. This is the tier that carries
     * PostgreSQL, since MySQL reports HY000 for a lost connection.
     *
     * @param string $sqlState
     */
    #[DataProvider('connectionSqlStates')]
    #[Test]
    public function connectionSqlStatesAreRecognised(string $sqlState): void
    {
        $inner = self::driverException('connection failure', $sqlState, 7);

        self::assertTrue(self::classify(new DbalDriverExceptionWrapper($inner, null)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function connectionSqlStates(): iterable
    {
        yield 'connection exception' => ['08000'];
        yield 'connection does not exist' => ['08003'];
        yield 'connection failure' => ['08006'];
        yield 'admin shutdown (postgres)' => ['57P01'];
    }

    /**
     * Tier 3 — the driver codes Doctrine leaves unmapped. 2013 is the notable
     * one: verified absent from its MySQL converter, so nothing above this
     * tier would catch it.
     *
     * @param int $driverCode
     */
    #[DataProvider('unmappedDriverCodes')]
    #[Test]
    public function driverCodesDoctrineDoesNotMapAreRecognised(int $driverCode): void
    {
        $inner = self::driverException('connection trouble', 'HY000', $driverCode);

        self::assertTrue(self::classify(new DbalDriverExceptionWrapper($inner, null)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unmappedDriverCodes(): iterable
    {
        yield 'lost connection during query' => [2013];
        yield 'lost connection, system error' => [2055];
        yield 'server shutdown in progress' => [1053];
    }

    /**
     * A failure that is NOT a lost connection must not be treated as one:
     * reconnecting and re-running a bad query just fails again, and for a
     * write it could apply twice.
     */
    #[Test]
    public function ordinaryQueryFailuresAreNotTreatedAsConnectionLoss(): void
    {
        $inner = self::driverException("Table 'app.missing' doesn't exist", '42S02', 1146);

        self::assertFalse(self::classify(new TableNotFoundException($inner, null)));
    }

    #[Test]
    public function unrelatedExceptionsAreNotTreatedAsConnectionLoss(): void
    {
        self::assertFalse(self::classify(new RuntimeException('something else entirely')));
    }

    /**
     * THE REGRESSION GUARD. Detection reads codes, never prose. Messages are
     * localized, reworded between releases and differ per driver, so matching
     * on them is a guess that silently stops working — and worse, it fires on
     * an exception that merely mentions a lost connection while carrying a
     * code that says otherwise.
     *
     * Every phrase below was in the message list this classifier used to
     * carry. Each is paired with a SQLSTATE and driver code that mean "not a
     * connection problem", so this test fails the moment message matching
     * comes back.
     *
     * @param string $message
     */
    #[DataProvider('formerlyMatchedPhrases')]
    #[Test]
    public function messageTextAloneNeverClassifiesAsConnectionLoss(string $message): void
    {
        $inner = self::driverException($message, '42S02', 1146);

        self::assertFalse(
            self::classify(new DbalDriverExceptionWrapper($inner, null)),
            'detection must read driver codes, not message text',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formerlyMatchedPhrases(): iterable
    {
        yield 'gone away' => ["a column named 'gone away' is missing"];
        yield 'lost connection' => ['the phrase lost connection appears in this data'];
        yield 'broken pipe' => ['broken pipe'];
        yield 'connection reset' => ['connection reset'];
        yield 'connection refused' => ['connection refused'];
        yield 'server closed the connection' => ['server closed the connection'];
        yield 'terminating connection' => ['terminating connection'];
        yield 'ssl connection has been closed' => ['ssl connection has been closed'];
    }
}

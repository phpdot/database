<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\DatabaseConnector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * What happens when the server closes a connection underneath us.
 *
 * These exercise the paths a pool depends on and that nothing else covers:
 * the connection reporting its own death rather than being interrogated, the
 * scrub never writing to a socket that is gone, and the reconnect-and-retry
 * that keeps a user from ever seeing any of it.
 *
 * `KILL` from a second session is how the server closing an idle connection
 * looks to the client, which is the real-world case (MySQL `wait_timeout`,
 * a restart, a network drop).
 */
#[Group('mysql')]
#[Group('integration')]
final class ConnectionLossTest extends MySqlTestCase
{
    /**
     * Outside a transaction the loss must never reach the caller: the
     * statement is retried on a fresh connection and simply succeeds.
     */
    #[Test]
    public function queryAfterServerClosedConnectionRecoversTransparently(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->killServerSide($this->connectionId($this->db));

        self::assertSame(5, $this->db->table('users')->count(), 'the retry must be invisible to the caller');
        self::assertTrue($this->db->isConnected());
    }

    /**
     * Inside a transaction the statement is deliberately NOT retried — that
     * would commit it outside the transaction — so the connection must record
     * that it is gone instead of going on claiming to be connected.
     */
    #[Test]
    public function connectionLostInsideTransactionMarksItselfDisconnected(): void
    {
        $this->createUsersTable();

        $this->db->beginTransaction();
        $this->killServerSide($this->connectionId($this->db));

        try {
            $this->db->select('SELECT 1');
            self::fail('a lost connection inside a transaction must not be swallowed');
        } catch (Throwable) {
            // expected — the transaction cannot be honoured
        }

        self::assertFalse(
            $this->db->isConnected(),
            'the connection must report its own death, or a pool re-pools a corpse',
        );
    }

    /**
     * `ping()` on a connection known lost must answer without touching the
     * socket. Writing to it raises "Send of N bytes failed with errno=32
     * Broken pipe" — a PHP diagnostic, not an exception, so no catch can
     * contain it and it escapes to the log looking like a fault.
     */
    #[Test]
    public function probingALostConnectionWritesNothingAndReportsDead(): void
    {
        $this->db->beginTransaction();
        $this->killServerSide($this->connectionId($this->db));

        try {
            $this->db->select('SELECT 1');
        } catch (Throwable) {
        }

        $diagnostics = [];
        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        $alive = (new DatabaseConnector($this->config()))->isAlive($this->db);

        restore_error_handler();

        self::assertFalse($alive, 'a lost connection must report dead');
        self::assertSame([], $diagnostics, 'nothing may be written to a dead socket');
    }

    /**
     * reset() rolls back a live connection's open transaction — the scrub a
     * pool relies on so one borrower's transaction cannot leak into the next.
     */
    #[Test]
    public function resetRollsBackAnOpenTransactionOnALiveConnection(): void
    {
        $this->createUsersTable();

        $this->db->beginTransaction();
        $this->db->table('users')->insert(['name' => 'Ghost', 'email' => 'ghost@example.com']);
        self::assertSame(1, $this->db->transactionLevel());

        $this->db->reset();

        self::assertSame(0, $this->db->transactionLevel(), 'reset must close the transaction');
        self::assertSame(0, $this->db->table('users')->count(), 'the uncommitted row must be gone');
    }

    /**
     * The same scrub on a connection already known lost must be silent: there
     * is nothing to roll back, and the rollback itself would be a write.
     */
    #[Test]
    public function resetOnALostConnectionWritesNothing(): void
    {
        $this->db->beginTransaction();
        $this->killServerSide($this->connectionId($this->db));

        try {
            $this->db->select('SELECT 1');
        } catch (Throwable) {
        }

        $diagnostics = [];
        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        $this->db->reset();

        restore_error_handler();

        self::assertSame([], $diagnostics, 'reset must not write to a socket that is gone');
    }

    /**
     * A pool borrowing after the server closed everything must still hand out
     * a working connection — the scenario an idle site hits with its first
     * visitor.
     */
    #[Test]
    public function connectorProducesAWorkingConnectionAfterTheServerClosedEverything(): void
    {
        $connector = new DatabaseConnector($this->config());
        $pooled = $connector->connect();

        $this->killServerSide($this->connectionId($pooled));

        self::assertFalse($connector->isAlive($pooled), 'the pool must be told to discard it');

        $replacement = $connector->connect();

        self::assertTrue($connector->isAlive($replacement));
        self::assertSame(1, (int) $replacement->selectOne('SELECT 1 AS one')['one']);

        $replacement->close();
    }

    /**
     * The connection id MySQL knows this connection by.
     *
     * @param DatabaseConnection $connection The connection to identify
     *
     * @return int
     */
    private function connectionId(DatabaseConnection $connection): int
    {
        $row = $connection->selectOne('SELECT CONNECTION_ID() AS id');

        return (int) ($row['id'] ?? 0);
    }

    /**
     * Close a connection from the server side, as MySQL does to an idle one.
     *
     * @param int $id The connection id to close
     *
     * @return void
     */
    private function killServerSide(int $id): void
    {
        $killer = new DatabaseConnection($this->config());
        $killer->unprepared('KILL ' . $id);
        $killer->close();

        usleep(200_000);
    }

    /**
     * @return MySqlConfig
     */
    private function config(): MySqlConfig
    {
        return new MySqlConfig(
            host: getenv('MYSQL_HOST') ?: '127.0.0.1',
            port: (int) (getenv('MYSQL_PORT') ?: 3306),
            database: getenv('MYSQL_DB') ?: 'phpdot_test',
            username: getenv('MYSQL_USER') ?: 'root',
            password: getenv('MYSQL_PASS') ?: 'root',
        );
    }
}

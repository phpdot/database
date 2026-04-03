<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit;

use PDO;
use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\Connection;
use PHPdot\Database\Exception\QueryException;
use PHPdot\Database\Query\Expression;
use PHPdot\Database\Result\ResultSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));

        $this->connection->unprepared(
            'CREATE TABLE test_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, active INTEGER DEFAULT 1)',
        );
        $this->connection->insert('INSERT INTO test_users (name, email) VALUES (?, ?)', ['Alice', 'alice@example.com']);
        $this->connection->insert('INSERT INTO test_users (name, email) VALUES (?, ?)', ['Bob', 'bob@example.com']);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    // ---------------------------------------------------------------
    //  Query methods
    // ---------------------------------------------------------------

    #[Test]
    public function selectReturnsResultSet(): void
    {
        $result = $this->connection->select('SELECT * FROM test_users');

        self::assertInstanceOf(ResultSet::class, $result);
        self::assertSame(2, $result->count());
    }

    #[Test]
    public function selectWithBindingsReturnsFilteredResults(): void
    {
        $result = $this->connection->select('SELECT * FROM test_users WHERE name = ?', ['Alice']);

        self::assertSame(1, $result->count());
        self::assertSame('Alice', $result->first()['name']);
    }

    #[Test]
    public function selectOneReturnsSingleRow(): void
    {
        $row = $this->connection->selectOne('SELECT * FROM test_users WHERE name = ?', ['Alice']);

        self::assertIsArray($row);
        self::assertSame('Alice', $row['name']);
    }

    #[Test]
    public function selectOneReturnsNullWhenNoMatch(): void
    {
        $row = $this->connection->selectOne('SELECT * FROM test_users WHERE name = ?', ['Nobody']);

        self::assertNull($row);
    }

    #[Test]
    public function insertReturnsTrueOnSuccess(): void
    {
        $result = $this->connection->insert(
            'INSERT INTO test_users (name, email) VALUES (?, ?)',
            ['Charlie', 'charlie@example.com'],
        );

        self::assertTrue($result);

        $count = $this->connection->select('SELECT COUNT(*) as cnt FROM test_users');
        self::assertSame(3, (int) $count->value('cnt'));
    }

    #[Test]
    public function updateReturnsAffectedRows(): void
    {
        $affected = $this->connection->update(
            'UPDATE test_users SET active = ? WHERE name = ?',
            [0, 'Alice'],
        );

        self::assertSame(1, $affected);
    }

    #[Test]
    public function deleteReturnsAffectedRows(): void
    {
        $affected = $this->connection->delete('DELETE FROM test_users WHERE name = ?', ['Bob']);

        self::assertSame(1, $affected);

        $count = $this->connection->select('SELECT COUNT(*) as cnt FROM test_users');
        self::assertSame(1, (int) $count->value('cnt'));
    }

    #[Test]
    public function statementReturnsBool(): void
    {
        $result = $this->connection->statement(
            'CREATE TABLE test_temp (id INTEGER PRIMARY KEY)',
        );

        self::assertTrue($result);
    }

    #[Test]
    public function unpreparedExecutesRawSql(): void
    {
        $result = $this->connection->unprepared('CREATE TABLE test_raw (id INTEGER PRIMARY KEY)');

        self::assertTrue($result);

        $this->connection->insert('INSERT INTO test_raw (id) VALUES (?)', [1]);
        $rows = $this->connection->select('SELECT * FROM test_raw');
        self::assertSame(1, $rows->count());
    }

    // ---------------------------------------------------------------
    //  Transactions
    // ---------------------------------------------------------------

    #[Test]
    public function transactionCommitsOnSuccess(): void
    {
        $result = $this->connection->transaction(function (Connection $conn): string {
            $conn->insert(
                'INSERT INTO test_users (name, email) VALUES (?, ?)',
                ['Charlie', 'charlie@example.com'],
            );

            return 'done';
        });

        self::assertSame('done', $result);

        $count = $this->connection->select('SELECT COUNT(*) as cnt FROM test_users');
        self::assertSame(3, (int) $count->value('cnt'));
    }

    #[Test]
    public function transactionRollsBackOnException(): void
    {
        try {
            $this->connection->transaction(function (Connection $conn): void {
                $conn->insert(
                    'INSERT INTO test_users (name, email) VALUES (?, ?)',
                    ['Charlie', 'charlie@example.com'],
                );

                throw new RuntimeException('Something went wrong');
            });
        } catch (RuntimeException) {
            // expected
        }

        $count = $this->connection->select('SELECT COUNT(*) as cnt FROM test_users');
        self::assertSame(2, (int) $count->value('cnt'));
    }

    #[Test]
    public function beginTransactionAndCommitManually(): void
    {
        $this->connection->beginTransaction();
        $this->connection->insert(
            'INSERT INTO test_users (name, email) VALUES (?, ?)',
            ['Charlie', 'charlie@example.com'],
        );
        $this->connection->commit();

        $count = $this->connection->select('SELECT COUNT(*) as cnt FROM test_users');
        self::assertSame(3, (int) $count->value('cnt'));
    }

    #[Test]
    public function beginTransactionAndRollBackManually(): void
    {
        $this->connection->beginTransaction();
        $this->connection->insert(
            'INSERT INTO test_users (name, email) VALUES (?, ?)',
            ['Charlie', 'charlie@example.com'],
        );
        $this->connection->rollBack();

        $count = $this->connection->select('SELECT COUNT(*) as cnt FROM test_users');
        self::assertSame(2, (int) $count->value('cnt'));
    }

    #[Test]
    public function transactionLevelTracksNesting(): void
    {
        self::assertSame(0, $this->connection->transactionLevel());

        $this->connection->beginTransaction();
        self::assertSame(1, $this->connection->transactionLevel());

        $this->connection->rollBack();
        self::assertSame(0, $this->connection->transactionLevel());
    }

    #[Test]
    public function transactionLevelReturnsZeroWhenNotConnected(): void
    {
        $conn = new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));

        self::assertSame(0, $conn->transactionLevel());
    }

    // ---------------------------------------------------------------
    //  Connection state
    // ---------------------------------------------------------------

    #[Test]
    public function pingReturnsTrueWhenConnected(): void
    {
        $this->connection->ensureConnected();

        self::assertTrue($this->connection->ping());
    }

    #[Test]
    public function isConnectedReturnsFalseBeforeFirstQuery(): void
    {
        $conn = new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));

        self::assertFalse($conn->isConnected());
    }

    #[Test]
    public function isConnectedReturnsTrueAfterQuery(): void
    {
        self::assertTrue($this->connection->isConnected());
    }

    #[Test]
    public function ensureConnectedConnectsLazily(): void
    {
        $conn = new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));

        self::assertFalse($conn->isConnected());

        $conn->ensureConnected();

        self::assertTrue($conn->isConnected());
    }

    // ---------------------------------------------------------------
    //  Info methods
    // ---------------------------------------------------------------

    #[Test]
    public function getDriverNameReturnsSqlite(): void
    {
        self::assertSame('sqlite', $this->connection->getDriverName());
    }

    #[Test]
    public function getDatabaseNameReturnsMemory(): void
    {
        self::assertSame(':memory:', $this->connection->getDatabaseName());
    }

    #[Test]
    public function getTablePrefixReturnsConfiguredPrefix(): void
    {
        $conn = new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
            prefix: 'app_',
        ));

        self::assertSame('app_', $conn->getTablePrefix());
    }

    #[Test]
    public function getTablePrefixReturnsEmptyStringByDefault(): void
    {
        self::assertSame('', $this->connection->getTablePrefix());
    }

    // ---------------------------------------------------------------
    //  Query log
    // ---------------------------------------------------------------

    #[Test]
    public function enableQueryLogTracksQueries(): void
    {
        $this->connection->enableQueryLog();
        $this->connection->select('SELECT * FROM test_users');

        $log = $this->connection->getQueryLog();

        self::assertCount(1, $log);
        self::assertSame('SELECT * FROM test_users', $log[0]['query']);
        self::assertSame([], $log[0]['bindings']);
        self::assertIsFloat($log[0]['time']);
    }

    #[Test]
    public function flushQueryLogClearsLog(): void
    {
        $this->connection->enableQueryLog();
        $this->connection->select('SELECT * FROM test_users');

        $flushed = $this->connection->flushQueryLog();

        self::assertCount(1, $flushed);
        self::assertSame([], $this->connection->getQueryLog());
    }

    #[Test]
    public function queryLogNotTrackedWhenDisabled(): void
    {
        $this->connection->select('SELECT * FROM test_users');

        self::assertSame([], $this->connection->getQueryLog());
    }

    #[Test]
    public function ringBufferDropsOldEntries(): void
    {
        $this->connection->enableQueryLog(maxEntries: 2);

        $this->connection->select('SELECT 1');
        $this->connection->select('SELECT 2');
        $this->connection->select('SELECT 3');

        $log = $this->connection->getQueryLog();

        self::assertCount(2, $log);
        self::assertSame('SELECT 2', $log[0]['query']);
        self::assertSame('SELECT 3', $log[1]['query']);
    }

    // ---------------------------------------------------------------
    //  Escape hatches
    // ---------------------------------------------------------------

    #[Test]
    public function getDbalReturnsDbalConnection(): void
    {
        $dbal = $this->connection->getDbal();

        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $dbal);
    }

    #[Test]
    public function getPdoReturnsPdoInstance(): void
    {
        $pdo = $this->connection->getPdo();

        self::assertInstanceOf(PDO::class, $pdo);
    }

    // ---------------------------------------------------------------
    //  Utility
    // ---------------------------------------------------------------

    #[Test]
    public function rawReturnsExpression(): void
    {
        $expression = $this->connection->raw('NOW()');

        self::assertInstanceOf(Expression::class, $expression);
        self::assertSame('NOW()', $expression->value);
    }

    // ---------------------------------------------------------------
    //  Close
    // ---------------------------------------------------------------

    #[Test]
    public function closeDisconnects(): void
    {
        self::assertTrue($this->connection->isConnected());

        $this->connection->close();

        self::assertFalse($this->connection->isConnected());
    }

    #[Test]
    public function closeOnAlreadyClosedConnectionDoesNothing(): void
    {
        $conn = new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));

        $conn->close();

        self::assertFalse($conn->isConnected());
    }

    // ---------------------------------------------------------------
    //  Error handling
    // ---------------------------------------------------------------

    #[Test]
    public function selectThrowsQueryExceptionOnBadSql(): void
    {
        $this->expectException(QueryException::class);

        $this->connection->select('SELECT * FROM nonexistent_table');
    }

    #[Test]
    public function pingReturnsFalseWhenNotConnected(): void
    {
        $conn = new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));

        self::assertFalse($conn->ping());
    }
}

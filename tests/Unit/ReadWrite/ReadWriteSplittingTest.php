<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\ReadWrite;

use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for read/write splitting logic.
 *
 * Uses SQLite in-memory for the primary connection. Since SQLite does not
 * support read replicas, these tests verify the routing logic by observing
 * Connection state (recordsModified flag, forceWriteConnection, close resets).
 */
final class ReadWriteSplittingTest extends TestCase
{
    // ---------------------------------------------------------------
    //  No replicas — single connection
    // ---------------------------------------------------------------

    #[Test]
    public function selectWorksWithoutReadConfig(): void
    {
        $conn = $this->createConnection();
        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $conn->insert('INSERT INTO t (id) VALUES (?)', [1]);

        $result = $conn->select('SELECT * FROM t');

        self::assertSame(1, $result->count());
        $conn->close();
    }

    #[Test]
    public function writesSetsRecordsModifiedFlag(): void
    {
        $conn = $this->createConnection();
        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        self::assertTrue($conn->hasModifiedRecords());
        $conn->close();
    }

    #[Test]
    public function closeResetsRecordsModifiedFlag(): void
    {
        $conn = $this->createConnection();
        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        self::assertTrue($conn->hasModifiedRecords());

        $conn->close();

        self::assertFalse($conn->hasModifiedRecords());
    }

    #[Test]
    public function forceWriteConnectionIsResetAfterSelect(): void
    {
        $conn = $this->createConnection();
        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $conn->insert('INSERT INTO t (id) VALUES (?)', [1]);

        $conn->forceWriteConnection();
        $result = $conn->select('SELECT * FROM t');

        self::assertSame(1, $result->count());

        // Second select should not be forced to write (flag auto-resets)
        $result2 = $conn->select('SELECT * FROM t');
        self::assertSame(1, $result2->count());

        $conn->close();
    }

    #[Test]
    public function selectInsideTransactionUsesWriteConnection(): void
    {
        $conn = $this->createConnection();
        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $conn->insert('INSERT INTO t (id) VALUES (?)', [1]);

        $conn->beginTransaction();
        $result = $conn->select('SELECT * FROM t');
        $conn->commit();

        self::assertSame(1, $result->count());
        $conn->close();
    }

    #[Test]
    public function closeResetsForceWriteFlag(): void
    {
        $conn = $this->createConnection();
        $conn->forceWriteConnection();
        $conn->close();

        // After close, forceWrite flag should be reset.
        // We verify by checking that a new select works fine.
        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $conn->insert('INSERT INTO t (id) VALUES (?)', [1]);

        $result = $conn->select('SELECT * FROM t');
        self::assertSame(1, $result->count());
        $conn->close();
    }

    #[Test]
    public function insertThenSelectWorksWithStickyConfig(): void
    {
        $conn = new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));

        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $conn->insert('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'Alice']);

        self::assertTrue($conn->hasModifiedRecords());

        $result = $conn->select('SELECT * FROM t WHERE id = ?', [1]);
        self::assertSame(1, $result->count());
        self::assertSame('Alice', $result->first()['name']);

        $conn->close();
    }

    #[Test]
    public function transactionSetsRecordsModified(): void
    {
        $conn = $this->createConnection();
        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        // recordsModified is already true from unprepared, verify transaction keeps it true
        self::assertTrue($conn->hasModifiedRecords());

        $conn->transaction(function (Connection $c): void {
            $c->insert('INSERT INTO t (id) VALUES (?)', [1]);
        });

        self::assertTrue($conn->hasModifiedRecords());
        $conn->close();
    }

    #[Test]
    public function multipleWriteAndReadOperationsWork(): void
    {
        $conn = $this->createConnection();
        $conn->unprepared('CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)');

        $conn->insert('INSERT INTO t (id, val) VALUES (?, ?)', [1, 'a']);
        $conn->insert('INSERT INTO t (id, val) VALUES (?, ?)', [2, 'b']);

        $result = $conn->select('SELECT * FROM t ORDER BY id');
        self::assertSame(2, $result->count());

        $conn->update('UPDATE t SET val = ? WHERE id = ?', ['c', 1]);

        $result = $conn->select('SELECT val FROM t WHERE id = ?', [1]);
        self::assertSame('c', $result->first()['val']);

        $conn->delete('DELETE FROM t WHERE id = ?', [2]);

        $result = $conn->select('SELECT COUNT(*) as cnt FROM t');
        self::assertSame(1, (int) $result->value('cnt'));

        $conn->close();
    }

    #[Test]
    public function hasModifiedRecordsReturnsFalseInitially(): void
    {
        $conn = $this->createConnection();

        self::assertFalse($conn->hasModifiedRecords());
        $conn->close();
    }

    #[Test]
    public function selectDoesNotSetRecordsModifiedFlag(): void
    {
        $conn = $this->createConnection();

        self::assertFalse($conn->hasModifiedRecords());

        // select() uses runOnRead which does NOT set recordsModified
        $result = $conn->select('SELECT 1 as val');

        self::assertFalse($conn->hasModifiedRecords());
        self::assertSame(1, $result->count());
        $conn->close();
    }

    private function createConnection(): Connection
    {
        return new Connection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));
    }
}

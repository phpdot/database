<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for read/write splitting with MySQL.
 *
 * Uses the same host configured as both primary and replica to verify
 * the routing logic works end-to-end without requiring a separate server.
 */
#[Group('mysql')]
final class ReadWriteTest extends MySqlTestCase
{
    private Connection $rwDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rwDb = new Connection(new DatabaseConfig(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            database: 'phpdot_test',
            username: 'root',
            password: 'root',
            read: [
                ['host' => 'localhost'],
            ],
            sticky: true,
        ));
    }

    protected function tearDown(): void
    {
        $this->rwDb->close();
        parent::tearDown();
    }

    #[Test]
    public function selectWorksWithReadReplicaConfig(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->rwDb->select('SELECT * FROM users');

        self::assertSame(5, $result->count());
    }

    #[Test]
    public function insertWorksWithReadReplicaConfig(): void
    {
        $this->createUsersTable();

        $this->rwDb->insert(
            'INSERT INTO users (name, email) VALUES (?, ?)',
            ['Test', 'test@example.com'],
        );

        $result = $this->rwDb->select('SELECT * FROM users WHERE email = ?', ['test@example.com']);
        self::assertSame(1, $result->count());
    }

    #[Test]
    public function stickyReadsAfterWriteUseWriteConnection(): void
    {
        $this->createUsersTable();

        // Write sets recordsModified flag
        $this->rwDb->insert(
            'INSERT INTO users (name, email) VALUES (?, ?)',
            ['Sticky', 'sticky@example.com'],
        );

        self::assertTrue($this->rwDb->hasModifiedRecords());

        // With sticky=true, reads after write go to primary
        $result = $this->rwDb->select('SELECT * FROM users WHERE email = ?', ['sticky@example.com']);
        self::assertSame(1, $result->count());
    }

    #[Test]
    public function transactionReadsUseWriteConnection(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->rwDb->transaction(function (Connection $conn): void {
            $result = $conn->select('SELECT COUNT(*) as cnt FROM users');
            self::assertSame(5, (int) $result->value('cnt'));

            $conn->insert(
                'INSERT INTO users (name, email) VALUES (?, ?)',
                ['InTx', 'intx@example.com'],
            );

            $result = $conn->select('SELECT COUNT(*) as cnt FROM users');
            self::assertSame(6, (int) $result->value('cnt'));
        });
    }

    #[Test]
    public function forceWriteConnectionRoutesToPrimary(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->rwDb->forceWriteConnection();
        $result = $this->rwDb->select('SELECT COUNT(*) as cnt FROM users');

        self::assertSame(5, (int) $result->value('cnt'));
    }

    #[Test]
    public function closeResetsAllFlags(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->rwDb->insert(
            'INSERT INTO users (name, email) VALUES (?, ?)',
            ['Reset', 'reset@example.com'],
        );

        self::assertTrue($this->rwDb->hasModifiedRecords());

        $this->rwDb->close();

        self::assertFalse($this->rwDb->hasModifiedRecords());
        self::assertFalse($this->rwDb->isConnected());
    }

    #[Test]
    public function multipleOperationsWithReadWriteSplitting(): void
    {
        $this->createUsersTable();

        // Insert (write)
        $this->rwDb->insert(
            'INSERT INTO users (name, email, age) VALUES (?, ?, ?)',
            ['Alice', 'alice@example.com', 30],
        );

        // Select (sticky -> write)
        $result = $this->rwDb->select('SELECT * FROM users');
        self::assertSame(1, $result->count());

        // Update (write)
        $this->rwDb->update('UPDATE users SET age = ? WHERE name = ?', [31, 'Alice']);

        // Select (sticky -> write)
        $result = $this->rwDb->select('SELECT age FROM users WHERE name = ?', ['Alice']);
        self::assertSame(31, (int) $result->first()['age']);

        // Delete (write)
        $this->rwDb->delete('DELETE FROM users WHERE name = ?', ['Alice']);

        // Select (sticky -> write)
        $result = $this->rwDb->select('SELECT COUNT(*) as cnt FROM users');
        self::assertSame(0, (int) $result->value('cnt'));
    }

    #[Test]
    public function builderUseWriteConnectionForcesWrite(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->rwDb->table('users')
            ->useWriteConnection()
            ->where('name', 'Alice')
            ->get();

        self::assertSame(1, $result->count());
    }

    #[Test]
    public function queryLogWorksWithReadWriteSplitting(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->rwDb->enableQueryLog();

        $this->rwDb->select('SELECT * FROM users');
        $this->rwDb->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['Log', 'log@example.com']);
        $this->rwDb->select('SELECT * FROM users WHERE name = ?', ['Log']);

        $log = $this->rwDb->getQueryLog();

        self::assertGreaterThanOrEqual(3, count($log));
        $this->rwDb->disableQueryLog();
    }
}

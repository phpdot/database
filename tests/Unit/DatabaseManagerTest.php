<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit;

use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\DatabaseManager;
use PHPdot\Database\Exception\ConnectionException;
use PHPdot\Database\Query\Builder;
use PHPdot\Database\Query\Expression;
use PHPdot\Database\Result\ResultSet;
use PHPdot\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseManagerTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        $this->manager = new DatabaseManager(
            configs: [
                'default' => new DatabaseConfig(driver: 'sqlite', database: ':memory:'),
                'secondary' => new DatabaseConfig(driver: 'sqlite', database: ':memory:'),
            ],
            default: 'default',
        );
    }

    #[Test]
    public function connectionReturnsConnectionInstance(): void
    {
        $connection = $this->manager->connection();

        self::assertInstanceOf(DatabaseConnection::class, $connection);
    }

    #[Test]
    public function connectionCachesInstances(): void
    {
        $first = $this->manager->connection();
        $second = $this->manager->connection();

        self::assertSame($first, $second);
    }

    #[Test]
    public function connectionThrowsForUnknownName(): void
    {
        $this->expectException(ConnectionException::class);

        $this->manager->connection('nonexistent');
    }

    #[Test]
    public function tableDelegatesToDefaultConnection(): void
    {
        $connection = $this->manager->connection();
        $connection->unprepared('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');

        $builder = $this->manager->table('test_table');

        self::assertInstanceOf(Builder::class, $builder);
    }

    #[Test]
    public function selectDelegatesToDefaultConnection(): void
    {
        $connection = $this->manager->connection();
        $connection->unprepared('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_table (name) VALUES (?)', ['Alice']);

        $result = $this->manager->select('SELECT * FROM test_table');

        self::assertInstanceOf(ResultSet::class, $result);
        self::assertSame(1, $result->count());
    }

    #[Test]
    public function insertDelegatesToDefaultConnection(): void
    {
        $connection = $this->manager->connection();
        $connection->unprepared('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');

        $result = $this->manager->insert('INSERT INTO test_table (name) VALUES (?)', ['Alice']);

        self::assertTrue($result);
    }

    #[Test]
    public function updateDelegatesToDefaultConnection(): void
    {
        $connection = $this->manager->connection();
        $connection->unprepared('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_table (name) VALUES (?)', ['Alice']);

        $affected = $this->manager->update('UPDATE test_table SET name = ? WHERE name = ?', ['Bob', 'Alice']);

        self::assertSame(1, $affected);
    }

    #[Test]
    public function deleteDelegatesToDefaultConnection(): void
    {
        $connection = $this->manager->connection();
        $connection->unprepared('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_table (name) VALUES (?)', ['Alice']);

        $affected = $this->manager->delete('DELETE FROM test_table WHERE name = ?', ['Alice']);

        self::assertSame(1, $affected);
    }

    #[Test]
    public function transactionDelegatesToDefaultConnection(): void
    {
        $connection = $this->manager->connection();
        $connection->unprepared('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');

        $result = $this->manager->transaction(function (DatabaseConnection $conn): string {
            $conn->insert('INSERT INTO test_table (name) VALUES (?)', ['Alice']);

            return 'done';
        });

        self::assertSame('done', $result);
    }

    #[Test]
    public function schemaDelegatesToDefaultConnection(): void
    {
        $schema = $this->manager->schema();

        self::assertInstanceOf(SchemaBuilder::class, $schema);
    }

    #[Test]
    public function rawDelegatesToDefaultConnection(): void
    {
        $expression = $this->manager->raw('NOW()');

        self::assertInstanceOf(Expression::class, $expression);
        self::assertSame('NOW()', $expression->value);
    }

    #[Test]
    public function getDefaultConnectionReturnsConfiguredDefault(): void
    {
        self::assertSame('default', $this->manager->getDefaultConnection());
    }

    #[Test]
    public function setDefaultConnectionChangesDefault(): void
    {
        $this->manager->setDefaultConnection('secondary');

        self::assertSame('secondary', $this->manager->getDefaultConnection());
    }

    #[Test]
    public function disconnectClosesAndRemovesConnection(): void
    {
        $connection = $this->manager->connection();
        self::assertCount(1, $this->manager->getConnections());

        $this->manager->disconnect();

        self::assertCount(0, $this->manager->getConnections());
    }

    #[Test]
    public function disconnectWithNameClosesSpecificConnection(): void
    {
        $this->manager->connection('default');
        $this->manager->connection('secondary');
        self::assertCount(2, $this->manager->getConnections());

        $this->manager->disconnect('secondary');

        self::assertCount(1, $this->manager->getConnections());
        self::assertArrayHasKey('default', $this->manager->getConnections());
    }

    #[Test]
    public function disconnectDoesNothingForUnresolvedConnection(): void
    {
        $this->manager->disconnect('nonexistent');

        self::assertCount(0, $this->manager->getConnections());
    }

    #[Test]
    public function reconnectCreatesNewConnection(): void
    {
        $original = $this->manager->connection();
        $reconnected = $this->manager->reconnect();

        self::assertInstanceOf(DatabaseConnection::class, $reconnected);
        self::assertNotSame($original, $reconnected);
    }

    #[Test]
    public function multipleNamedConnectionsWorkIndependently(): void
    {
        $default = $this->manager->connection('default');
        $secondary = $this->manager->connection('secondary');

        self::assertNotSame($default, $secondary);

        $default->unprepared('CREATE TABLE default_table (id INTEGER PRIMARY KEY)');
        $secondary->unprepared('CREATE TABLE secondary_table (id INTEGER PRIMARY KEY)');

        $default->insert('INSERT INTO default_table (id) VALUES (?)', [1]);
        $secondary->insert('INSERT INTO secondary_table (id) VALUES (?)', [1]);

        self::assertSame(1, $default->select('SELECT * FROM default_table')->count());
        self::assertSame(1, $secondary->select('SELECT * FROM secondary_table')->count());
    }

    #[Test]
    public function getConnectionsReturnsAllResolved(): void
    {
        self::assertSame([], $this->manager->getConnections());

        $this->manager->connection('default');
        self::assertCount(1, $this->manager->getConnections());

        $this->manager->connection('secondary');
        self::assertCount(2, $this->manager->getConnections());
    }
}

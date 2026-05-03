<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Query\Expression;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
final class ConnectionResilienceTest extends MySqlTestCase
{
    public function testPingReturnsTrueWhenConnected(): void
    {
        $this->db->ensureConnected();

        self::assertTrue($this->db->ping());
    }

    public function testIsConnectedReturnsFalseBeforeQueries(): void
    {
        // Create a fresh connection that hasn't been used yet
        $fresh = new \PHPdot\Database\DatabaseConnection(new \PHPdot\Database\Config\DatabaseConfig(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            database: 'phpdot_test',
            username: 'root',
            password: 'root',
        ));

        self::assertFalse($fresh->isConnected());
    }

    public function testEnsureConnectedConnectsLazily(): void
    {
        $fresh = new \PHPdot\Database\DatabaseConnection(new \PHPdot\Database\Config\DatabaseConfig(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            database: 'phpdot_test',
            username: 'root',
            password: 'root',
        ));

        self::assertFalse($fresh->isConnected());

        $fresh->ensureConnected();

        self::assertTrue($fresh->isConnected());
        $fresh->close();
    }

    public function testCloseDisconnects(): void
    {
        $this->db->ensureConnected();
        self::assertTrue($this->db->isConnected());

        $this->db->close();
        self::assertFalse($this->db->isConnected());
    }

    public function testQueryAfterCloseTriggersReconnect(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->db->close();
        self::assertFalse($this->db->isConnected());

        // Querying should auto-reconnect
        $count = $this->db->table('users')->count();
        self::assertSame(5, $count);
        self::assertTrue($this->db->isConnected());
    }

    public function testQueryLogCapturesQueries(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->db->enableQueryLog();
        $this->db->table('users')->count();
        $this->db->table('users')->where('name', 'Alice')->first();

        $log = $this->db->getQueryLog();

        self::assertGreaterThanOrEqual(2, count($log));
        self::assertArrayHasKey('query', $log[0]);
        self::assertArrayHasKey('bindings', $log[0]);
        self::assertArrayHasKey('time', $log[0]);

        $this->db->disableQueryLog();
    }

    public function testQueryLogRingBufferDropsOldEntries(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->db->enableQueryLog(3);

        $this->db->table('users')->where('id', 1)->first();
        $this->db->table('users')->where('id', 2)->first();
        $this->db->table('users')->where('id', 3)->first();
        $this->db->table('users')->where('id', 4)->first();
        $this->db->table('users')->where('id', 5)->first();

        $log = $this->db->getQueryLog();

        self::assertCount(3, $log);

        $this->db->disableQueryLog();
    }

    public function testGetDriverNameReturnsMysql(): void
    {
        self::assertSame('mysql', $this->db->getDriverName());
    }

    public function testGetDatabaseNameReturnsPhpdotTest(): void
    {
        self::assertSame('phpdot_test', $this->db->getDatabaseName());
    }

    public function testRawReturnsExpression(): void
    {
        $expr = $this->db->raw('NOW()');

        self::assertInstanceOf(Expression::class, $expr);
        self::assertSame('NOW()', $expr->value);
    }
}

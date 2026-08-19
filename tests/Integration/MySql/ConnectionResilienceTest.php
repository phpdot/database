<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Query\Expression;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('mysql')]
#[Group('integration')]
final class ConnectionResilienceTest extends MySqlTestCase
{
    #[Test]
    public function pingReturnsTrueWhenConnected(): void
    {
        $this->db->ensureConnected();

        self::assertTrue($this->db->ping());
    }

    #[Test]
    public function isConnectedReturnsFalseBeforeQueries(): void
    {
        $fresh = new \PHPdot\Database\DatabaseConnection(new \PHPdot\Database\Connection\MySql\MySqlConfig(
            host: getenv('MYSQL_HOST') ?: '127.0.0.1',
            port: (int) (getenv('MYSQL_PORT') ?: 3306),
            database: 'phpdot_test',
            username: 'root',
            password: 'root',
        ));

        self::assertFalse($fresh->isConnected());
    }

    #[Test]
    public function ensureConnectedConnectsLazily(): void
    {
        $fresh = new \PHPdot\Database\DatabaseConnection(new \PHPdot\Database\Connection\MySql\MySqlConfig(
            host: getenv('MYSQL_HOST') ?: '127.0.0.1',
            port: (int) (getenv('MYSQL_PORT') ?: 3306),
            database: 'phpdot_test',
            username: 'root',
            password: 'root',
        ));

        self::assertFalse($fresh->isConnected());

        $fresh->ensureConnected();

        self::assertTrue($fresh->isConnected());
        $fresh->close();
    }

    #[Test]
    public function closeDisconnects(): void
    {
        $this->db->ensureConnected();
        self::assertTrue($this->db->isConnected());

        $this->db->close();
        self::assertFalse($this->db->isConnected());
    }

    #[Test]
    public function queryAfterCloseTriggersReconnect(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->db->close();
        self::assertFalse($this->db->isConnected());

        $count = $this->db->table('users')->count();
        self::assertSame(5, $count);
        self::assertTrue($this->db->isConnected());
    }

    #[Test]
    public function queryLogCapturesQueries(): void
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

    #[Test]
    public function queryLogRingBufferDropsOldEntries(): void
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

    #[Test]
    public function getDriverNameReturnsMysql(): void
    {
        self::assertSame('mysql', $this->db->getDriverName());
    }

    #[Test]
    public function getDatabaseNameReturnsPhpdotTest(): void
    {
        self::assertSame('phpdot_test', $this->db->getDatabaseName());
    }

    #[Test]
    public function rawReturnsExpression(): void
    {
        $expr = $this->db->raw('NOW()');

        self::assertInstanceOf(Expression::class, $expr);
        self::assertSame('NOW()', $expr->value);
    }
}

<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\PostgreSql;

use PHPdot\Database\Exception\QueryException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pgsql')]
final class QueryBuilderTest extends PostgreSqlTestCase
{
    public function testConnectsWithDefaultCharset(): void
    {
        self::assertTrue($this->db->isConnected());
        self::assertSame('pgsql', $this->db->getDriverName());
    }

    public function testInsertGetIdUsesReturning(): void
    {
        $this->createUsersTable();

        $id = $this->db->table('users')->insertGetId(['name' => 'Ann', 'email' => 'ann@example.com', 'age' => 40]);

        self::assertSame(1, $id);
    }

    public function testSelectAndAggregates(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        self::assertSame(5, $this->db->table('users')->count());
        self::assertSame(4, $this->db->table('users')->where('active', 1)->count());
        self::assertSame(3, $this->db->table('users')->where('age', '>', 27)->count());
        self::assertSame(35, (int) $this->db->table('users')->max('age'));
    }

    public function testUniqueConstraintEnforced(): void
    {
        $this->createUsersTable();
        $this->db->table('users')->insert(['name' => 'A', 'email' => 'dup@example.com']);

        $this->expectException(QueryException::class);
        $this->db->table('users')->insert(['name' => 'B', 'email' => 'dup@example.com']);
    }

    public function testWhereJsonContainsAndLength(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        self::assertSame(1, $this->db->table('users')->whereJsonContains('tags', '["vip"]')->count());
        self::assertSame(5, $this->db->table('users')->whereJsonLength('tags', '>=', 1)->count());
    }

    public function testTransactionRollback(): void
    {
        $this->createUsersTable();

        try {
            $this->db->transaction(function (): void {
                $this->db->table('users')->insert(['name' => 'Temp', 'email' => 'temp@example.com']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->db->table('users')->count());
    }
}

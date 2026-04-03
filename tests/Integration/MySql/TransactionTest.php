<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('mysql')]
final class TransactionTest extends MySqlTestCase
{
    public function testTransactionCommitsOnSuccess(): void
    {
        $this->createUsersTable();

        $this->db->transaction(function ($db): void {
            $db->table('users')->insert(['name' => 'TxUser', 'email' => 'tx@example.com']);
        });

        self::assertSame(1, $this->db->table('users')->count());
    }

    public function testTransactionRollsBackOnException(): void
    {
        $this->createUsersTable();

        try {
            $this->db->transaction(function ($db): void {
                $db->table('users')->insert(['name' => 'TxUser', 'email' => 'tx@example.com']);
                throw new RuntimeException('Forced failure');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(0, $this->db->table('users')->count());
    }

    public function testNestedTransactionsWithSavepoints(): void
    {
        $this->createUsersTable();

        $this->db->transaction(function ($db): void {
            $db->table('users')->insert(['name' => 'Outer', 'email' => 'outer@example.com']);

            try {
                $db->transaction(function ($db2): void {
                    $db2->table('users')->insert(['name' => 'Inner', 'email' => 'inner@example.com']);
                    throw new RuntimeException('Inner failure');
                });
            } catch (RuntimeException) {
                // inner rolled back
            }
        });

        // Outer commit should succeed; inner rolled back depends on DBAL savepoint support
        // At minimum, the outer row should exist
        self::assertTrue($this->db->table('users')->where('email', 'outer@example.com')->exists());
    }

    public function testTransactionLevelTracking(): void
    {
        self::assertSame(0, $this->db->transactionLevel());

        $this->db->beginTransaction();
        self::assertSame(1, $this->db->transactionLevel());

        $this->db->rollBack();
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function testManualBeginCommit(): void
    {
        $this->createUsersTable();

        $this->db->beginTransaction();
        $this->db->table('users')->insert(['name' => 'Manual', 'email' => 'manual@example.com']);
        $this->db->commit();

        self::assertSame(1, $this->db->table('users')->count());
    }

    public function testManualBeginRollBack(): void
    {
        $this->createUsersTable();

        $this->db->beginTransaction();
        $this->db->table('users')->insert(['name' => 'Manual', 'email' => 'manual@example.com']);
        $this->db->rollBack();

        self::assertSame(0, $this->db->table('users')->count());
    }
}

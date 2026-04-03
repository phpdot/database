<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\Sqlite;

use RuntimeException;

final class TransactionTest extends SqliteTestCase
{
    public function testCommitPersistsData(): void
    {
        $this->db->transaction(function ($db): void {
            $db->table('users')->insert(['name' => 'TxUser', 'email' => 'tx@example.com']);
        });

        self::assertSame(1, $this->db->table('users')->count());
    }

    public function testRollbackDiscardsData(): void
    {
        try {
            $this->db->transaction(function ($db): void {
                $db->table('users')->insert(['name' => 'TxUser', 'email' => 'tx@example.com']);
                throw new RuntimeException('Forced failure');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->db->table('users')->count());
    }

    public function testNestedWithSavepoints(): void
    {
        $this->db->transaction(function ($db): void {
            $db->table('users')->insert(['name' => 'Outer', 'email' => 'outer@example.com']);

            try {
                $db->transaction(function ($db2): void {
                    $db2->table('users')->insert(['name' => 'Inner', 'email' => 'inner@example.com']);
                    throw new RuntimeException('Inner failure');
                });
            } catch (RuntimeException) {
            }
        });

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
}

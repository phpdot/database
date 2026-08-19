<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\Sqlite;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class TransactionTest extends SqliteTestCase
{
    #[Test]
    public function commitPersistsData(): void
    {
        $this->db->transaction(function ($db): void {
            $db->table('users')->insert(['name' => 'TxUser', 'email' => 'tx@example.com']);
        });

        self::assertSame(1, $this->db->table('users')->count());
    }

    #[Test]
    public function rollbackDiscardsData(): void
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

    #[Test]
    public function nestedWithSavepoints(): void
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

    #[Test]
    public function transactionLevelTracking(): void
    {
        self::assertSame(0, $this->db->transactionLevel());

        $this->db->beginTransaction();
        self::assertSame(1, $this->db->transactionLevel());

        $this->db->rollBack();
        self::assertSame(0, $this->db->transactionLevel());
    }
}

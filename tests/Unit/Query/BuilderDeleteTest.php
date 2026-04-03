<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPdot\Database\Query\Grammar\PostgresGrammar;
use PHPdot\Database\Query\Grammar\SqliteGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuilderDeleteTest extends TestCase
{
    #[Test]
    public function deleteMysql(): void
    {
        $grammar = new MySqlGrammar();
        $wheres = [
            ['type' => 'basic', 'column' => 'id', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
        ];

        $sql = $grammar->compileDelete('users', $wheres, [1]);

        self::assertSame('DELETE FROM `users` WHERE `id` = ?', $sql);
    }

    #[Test]
    public function deleteWithoutWhere(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileDelete('users', [], []);

        self::assertSame('DELETE FROM `users`', $sql);
    }

    #[Test]
    public function deleteMultipleWheres(): void
    {
        $grammar = new MySqlGrammar();
        $wheres = [
            ['type' => 'basic', 'column' => 'active', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ['type' => 'null', 'column' => 'deleted_at', 'boolean' => 'and'],
        ];

        $sql = $grammar->compileDelete('users', $wheres, [0]);

        self::assertSame('DELETE FROM `users` WHERE `active` = ? AND `deleted_at` IS NULL', $sql);
    }

    #[Test]
    public function truncateMysql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileTruncate('users');

        self::assertSame('TRUNCATE TABLE `users`', $sql);
    }

    #[Test]
    public function truncatePostgres(): void
    {
        $grammar = new PostgresGrammar();
        $sql = $grammar->compileTruncate('users');

        self::assertSame('TRUNCATE TABLE "users" RESTART IDENTITY CASCADE', $sql);
    }

    #[Test]
    public function truncateSqlite(): void
    {
        $grammar = new SqliteGrammar();
        $sql = $grammar->compileTruncate('users');

        self::assertSame('DELETE FROM "users"', $sql);
    }

    #[Test]
    public function deletePostgres(): void
    {
        $grammar = new PostgresGrammar();
        $wheres = [
            ['type' => 'basic', 'column' => 'id', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
        ];

        $sql = $grammar->compileDelete('users', $wheres, [1]);

        self::assertSame('DELETE FROM "users" WHERE "id" = ?', $sql);
    }
}

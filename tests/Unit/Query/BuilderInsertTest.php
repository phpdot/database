<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPdot\Database\Query\Grammar\PostgresGrammar;
use PHPdot\Database\Query\Grammar\SqliteGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuilderInsertTest extends TestCase
{
    #[Test]
    public function insertMysql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileInsert('users', ['name' => '?', 'email' => '?']);

        self::assertSame('INSERT INTO `users` (`name`, `email`) VALUES (?, ?)', $sql);
    }

    #[Test]
    public function insertBatchMysql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileInsertBatch('users', ['name', 'email'], [
            ['?', '?'],
            ['?', '?'],
        ]);

        self::assertSame('INSERT INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?)', $sql);
    }

    #[Test]
    public function insertOrIgnoreMysql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileInsertOrIgnore('users', ['name' => '?', 'email' => '?']);

        self::assertSame('INSERT IGNORE INTO `users` (`name`, `email`) VALUES (?, ?)', $sql);
    }

    #[Test]
    public function insertOrIgnorePostgres(): void
    {
        $grammar = new PostgresGrammar();
        $sql = $grammar->compileInsertOrIgnore('users', ['name' => '?', 'email' => '?']);

        self::assertSame('INSERT INTO "users" ("name", "email") VALUES (?, ?) ON CONFLICT DO NOTHING', $sql);
    }

    #[Test]
    public function insertOrIgnoreSqlite(): void
    {
        $grammar = new SqliteGrammar();
        $sql = $grammar->compileInsertOrIgnore('users', ['name' => '?', 'email' => '?']);

        self::assertSame('INSERT OR IGNORE INTO "users" ("name", "email") VALUES (?, ?)', $sql);
    }

    #[Test]
    public function upsertMysql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileUpsert('users', ['email' => '?', 'name' => '?'], ['email'], ['name']);

        self::assertSame(
            'INSERT INTO `users` (`email`, `name`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)',
            $sql,
        );
    }

    #[Test]
    public function upsertPostgres(): void
    {
        $grammar = new PostgresGrammar();
        $sql = $grammar->compileUpsert('users', ['email' => '?', 'name' => '?'], ['email'], ['name']);

        self::assertSame(
            'INSERT INTO "users" ("email", "name") VALUES (?, ?) ON CONFLICT ("email") DO UPDATE SET "name" = "excluded"."name"',
            $sql,
        );
    }

    #[Test]
    public function upsertSqlite(): void
    {
        $grammar = new SqliteGrammar();
        $sql = $grammar->compileUpsert('users', ['email' => '?', 'name' => '?'], ['email'], ['name']);

        self::assertSame(
            'INSERT INTO "users" ("email", "name") VALUES (?, ?) ON CONFLICT ("email") DO UPDATE SET "name" = "excluded"."name"',
            $sql,
        );
    }

    #[Test]
    public function insertGetIdPostgres(): void
    {
        $grammar = new PostgresGrammar();
        $sql = $grammar->compileInsertGetId('users', ['name' => '?'], 'id');

        self::assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING "id"', $sql);
    }

    #[Test]
    public function insertGetIdSqlite(): void
    {
        $grammar = new SqliteGrammar();
        $sql = $grammar->compileInsertGetId('users', ['name' => '?'], 'id');

        self::assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING "id"', $sql);
    }

    #[Test]
    public function insertUsingMysql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileInsertUsing('archive', ['name', 'email'], 'SELECT `name`, `email` FROM `users`');

        self::assertSame(
            'INSERT INTO `archive` (`name`, `email`) SELECT `name`, `email` FROM `users`',
            $sql,
        );
    }
}

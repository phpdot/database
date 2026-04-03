<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query\Grammar;

use PHPdot\Database\Query\Grammar\SqliteGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqliteGrammarTest extends TestCase
{
    private SqliteGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new SqliteGrammar();
    }

    #[Test]
    public function doubleQuoteQuoting(): void
    {
        self::assertSame('"users"', $this->grammar->wrap('users'));
        self::assertSame('"users"."name"', $this->grammar->wrap('users.name'));
        self::assertSame('*', $this->grammar->wrap('*'));
    }

    #[Test]
    public function wrapAlias(): void
    {
        self::assertSame('"name" AS "display_name"', $this->grammar->wrap('name as display_name'));
    }

    #[Test]
    public function insertOrIgnoreSyntax(): void
    {
        $sql = $this->grammar->compileInsertOrIgnore('users', ['name' => '?']);

        self::assertSame('INSERT OR IGNORE INTO "users" ("name") VALUES (?)', $sql);
    }

    #[Test]
    public function onConflictDoUpdateSyntax(): void
    {
        $sql = $this->grammar->compileUpsert('users', ['email' => '?', 'name' => '?'], ['email'], ['name']);

        self::assertSame(
            'INSERT INTO "users" ("email", "name") VALUES (?, ?) ON CONFLICT ("email") DO UPDATE SET "name" = "excluded"."name"',
            $sql,
        );
    }

    #[Test]
    public function noLock(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'lock' => true,
        ]);

        // SQLite does not support row-level locking, so no lock clause
        self::assertSame('SELECT * FROM "users"', $sql);
    }

    #[Test]
    public function deleteFromInsteadOfTruncate(): void
    {
        $sql = $this->grammar->compileTruncate('users');

        self::assertSame('DELETE FROM "users"', $sql);
    }

    #[Test]
    public function strftimeDateFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'date', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame("SELECT * FROM \"users\" WHERE strftime('%Y-%m-%d', \"created_at\") = ?", $sql);
    }

    #[Test]
    public function strftimeTimeFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'time', 'column' => 'created_at', 'operator' => '>', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame("SELECT * FROM \"users\" WHERE strftime('%H:%M:%S', \"created_at\") > ?", $sql);
    }

    #[Test]
    public function strftimeYearFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'year', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame("SELECT * FROM \"users\" WHERE CAST(strftime('%Y', \"created_at\") AS INTEGER) = ?", $sql);
    }

    #[Test]
    public function strftimeMonthFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'month', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame("SELECT * FROM \"users\" WHERE CAST(strftime('%m', \"created_at\") AS INTEGER) = ?", $sql);
    }

    #[Test]
    public function strftimeDayFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'day', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame("SELECT * FROM \"users\" WHERE CAST(strftime('%d', \"created_at\") AS INTEGER) = ?", $sql);
    }

    #[Test]
    public function jsonEachContains(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonContains', 'column' => 'tags', 'value' => '?', 'not' => false, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM json_each("tags") WHERE "json_each"."value" IS ?)',
            $sql,
        );
    }

    #[Test]
    public function jsonEachContainsNot(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonContains', 'column' => 'tags', 'value' => '?', 'not' => true, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame(
            'SELECT * FROM "users" WHERE NOT EXISTS (SELECT 1 FROM json_each("tags") WHERE "json_each"."value" IS ?)',
            $sql,
        );
    }

    #[Test]
    public function jsonArrayLengthSyntax(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonLength', 'column' => 'tags', 'operator' => '>', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE json_array_length("tags") > ?', $sql);
    }

    #[Test]
    public function fullTextFallbackToLike(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'posts',
            'wheres' => [
                ['type' => 'fullText', 'columns' => ['title', 'body'], 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "posts" WHERE ("title" LIKE ? OR "body" LIKE ?)', $sql);
    }

    #[Test]
    public function likeSyntax(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'like', 'column' => 'name', 'value' => '?', 'not' => false, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "name" LIKE ?', $sql);
    }

    #[Test]
    public function notLikeSyntax(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'like', 'column' => 'name', 'value' => '?', 'not' => true, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "name" NOT LIKE ?', $sql);
    }

    #[Test]
    public function randomOrder(): void
    {
        self::assertSame('RANDOM()', $this->grammar->compileRandomOrder());
    }

    #[Test]
    public function insertGetIdReturning(): void
    {
        $sql = $this->grammar->compileInsertGetId('users', ['name' => '?'], 'id');

        self::assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING "id"', $sql);
    }

    #[Test]
    public function compileSelectFull(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'basic', 'column' => 'active', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
            'orders' => [
                ['column' => 'name', 'direction' => 'ASC'],
            ],
            'limit' => 10,
            'offset' => 5,
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "active" = ? ORDER BY "name" ASC LIMIT 10 OFFSET 5', $sql);
    }
}

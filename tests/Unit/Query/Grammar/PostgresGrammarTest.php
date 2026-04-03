<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query\Grammar;

use PHPdot\Database\Query\Grammar\PostgresGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostgresGrammarTest extends TestCase
{
    private PostgresGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new PostgresGrammar();
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
    public function onConflictDoNothingSyntax(): void
    {
        $sql = $this->grammar->compileInsertOrIgnore('users', ['name' => '?']);

        self::assertSame('INSERT INTO "users" ("name") VALUES (?) ON CONFLICT DO NOTHING', $sql);
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
    public function upsertMultipleUniqueColumns(): void
    {
        $sql = $this->grammar->compileUpsert(
            'users',
            ['email' => '?', 'tenant_id' => '?', 'name' => '?'],
            ['email', 'tenant_id'],
            ['name'],
        );

        self::assertStringContainsString('ON CONFLICT ("email", "tenant_id")', $sql);
    }

    #[Test]
    public function returningClause(): void
    {
        $sql = $this->grammar->compileInsertGetId('users', ['name' => '?'], 'id');

        self::assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING "id"', $sql);
    }

    #[Test]
    public function forUpdateLock(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'lock' => true,
        ]);

        self::assertSame('SELECT * FROM "users" FOR UPDATE', $sql);
    }

    #[Test]
    public function forShareLock(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'lock' => 'FOR SHARE',
        ]);

        self::assertSame('SELECT * FROM "users" FOR SHARE', $sql);
    }

    #[Test]
    public function extractDateFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'date', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "created_at"::date = ?', $sql);
    }

    #[Test]
    public function extractTimeFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'time', 'column' => 'created_at', 'operator' => '>', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "created_at"::time > ?', $sql);
    }

    #[Test]
    public function extractYearFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'year', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE EXTRACT(YEAR FROM "created_at") = ?', $sql);
    }

    #[Test]
    public function extractMonthFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'month', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE EXTRACT(MONTH FROM "created_at") = ?', $sql);
    }

    #[Test]
    public function extractDayFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'day', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE EXTRACT(DAY FROM "created_at") = ?', $sql);
    }

    #[Test]
    public function jsonbContainmentOperator(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonContains', 'column' => 'tags', 'value' => '?', 'not' => false, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "tags"::jsonb @> ?', $sql);
    }

    #[Test]
    public function jsonbContainmentOperatorNot(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonContains', 'column' => 'tags', 'value' => '?', 'not' => true, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE NOT ("tags"::jsonb @> ?)', $sql);
    }

    #[Test]
    public function jsonbArrayLength(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonLength', 'column' => 'tags', 'operator' => '>', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE jsonb_array_length("tags"::jsonb) > ?', $sql);
    }

    #[Test]
    public function ilikeSyntax(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'like', 'column' => 'name', 'value' => '?', 'not' => false, 'caseSensitive' => false, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "name" ILIKE ?', $sql);
    }

    #[Test]
    public function likeCaseSensitive(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'like', 'column' => 'name', 'value' => '?', 'not' => false, 'caseSensitive' => true, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "name" LIKE ?', $sql);
    }

    #[Test]
    public function notIlike(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'like', 'column' => 'name', 'value' => '?', 'not' => true, 'caseSensitive' => false, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM "users" WHERE "name" NOT ILIKE ?', $sql);
    }

    #[Test]
    public function fullTextTsVector(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'posts',
            'wheres' => [
                ['type' => 'fullText', 'columns' => ['title', 'body'], 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame(
            'SELECT * FROM "posts" WHERE to_tsvector("title") || to_tsvector("body") @@ plainto_tsquery(?)',
            $sql,
        );
    }

    #[Test]
    public function randomOrder(): void
    {
        self::assertSame('RANDOM()', $this->grammar->compileRandomOrder());
    }

    #[Test]
    public function truncate(): void
    {
        self::assertSame('TRUNCATE TABLE "users" RESTART IDENTITY CASCADE', $this->grammar->compileTruncate('users'));
    }
}

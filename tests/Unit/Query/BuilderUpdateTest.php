<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Query\Expression;
use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPdot\Database\Query\Grammar\PostgresGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuilderUpdateTest extends TestCase
{
    #[Test]
    public function updateMysql(): void
    {
        $grammar = new MySqlGrammar();
        $wheres = [
            ['type' => 'basic', 'column' => 'id', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
        ];

        $sql = $grammar->compileUpdate('users', ['name' => '?', 'email' => '?'], $wheres, ['newname', 'new@e.com', 1]);

        self::assertSame('UPDATE `users` SET `name` = ?, `email` = ? WHERE `id` = ?', $sql);
    }

    #[Test]
    public function updateWithoutWhere(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileUpdate('users', ['active' => '?'], [], [0]);

        self::assertSame('UPDATE `users` SET `active` = ?', $sql);
    }

    #[Test]
    public function updateWithExpression(): void
    {
        $grammar = new MySqlGrammar();
        $wheres = [
            ['type' => 'basic', 'column' => 'id', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
        ];

        $sql = $grammar->compileUpdate(
            'users',
            ['views' => new Expression('`views` + 1')],
            $wheres,
            [1],
        );

        self::assertSame('UPDATE `users` SET `views` = `views` + 1 WHERE `id` = ?', $sql);
    }

    #[Test]
    public function updatePostgres(): void
    {
        $grammar = new PostgresGrammar();
        $wheres = [
            ['type' => 'basic', 'column' => 'id', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
        ];

        $sql = $grammar->compileUpdate('users', ['name' => '?'], $wheres, ['newname', 1]);

        self::assertSame('UPDATE "users" SET "name" = ? WHERE "id" = ?', $sql);
    }

    #[Test]
    public function incrementExpression(): void
    {
        $grammar = new MySqlGrammar();
        $expression = new Expression($grammar->wrap('views') . ' + 1');

        self::assertSame('`views` + 1', $expression->value);
    }

    #[Test]
    public function decrementExpression(): void
    {
        $grammar = new MySqlGrammar();
        $expression = new Expression($grammar->wrap('stock') . ' - 5');

        self::assertSame('`stock` - 5', $expression->value);
    }
}

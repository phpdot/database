<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Query\Expression;
use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Aggregate tests verify that the Grammar produces correct aggregate SQL.
 * Since aggregate methods call get() which requires a real connection,
 * we test the SQL compilation by passing Expression objects as columns.
 */
final class BuilderAggregateTest extends TestCase
{
    #[Test]
    public function compileCountSql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileSelect([
            'columns' => [new Expression('COUNT(*) AS `aggregate`')],
            'from' => 'users',
        ]);

        self::assertSame('SELECT COUNT(*) AS `aggregate` FROM `users`', $sql);
    }

    #[Test]
    public function compileSumSql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileSelect([
            'columns' => [new Expression('SUM(`amount`) AS `aggregate`')],
            'from' => 'orders',
        ]);

        self::assertSame('SELECT SUM(`amount`) AS `aggregate` FROM `orders`', $sql);
    }

    #[Test]
    public function compileAvgSql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileSelect([
            'columns' => [new Expression('AVG(`price`) AS `aggregate`')],
            'from' => 'products',
        ]);

        self::assertSame('SELECT AVG(`price`) AS `aggregate` FROM `products`', $sql);
    }

    #[Test]
    public function compileMinSql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileSelect([
            'columns' => [new Expression('MIN(`price`) AS `aggregate`')],
            'from' => 'products',
        ]);

        self::assertSame('SELECT MIN(`price`) AS `aggregate` FROM `products`', $sql);
    }

    #[Test]
    public function compileMaxSql(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileSelect([
            'columns' => [new Expression('MAX(`price`) AS `aggregate`')],
            'from' => 'products',
        ]);

        self::assertSame('SELECT MAX(`price`) AS `aggregate` FROM `products`', $sql);
    }

    #[Test]
    public function existsSql(): void
    {
        $grammar = new MySqlGrammar();
        $innerSql = 'SELECT * FROM `users` WHERE `active` = ?';
        $sql = $grammar->compileExists($innerSql);

        self::assertSame('SELECT EXISTS(SELECT * FROM `users` WHERE `active` = ?) AS `exists`', $sql);
    }

    #[Test]
    public function aggregateWithWhereClause(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileSelect([
            'columns' => [new Expression('COUNT(*) AS `aggregate`')],
            'from' => 'users',
            'wheres' => [
                ['type' => 'basic', 'column' => 'active', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT COUNT(*) AS `aggregate` FROM `users` WHERE `active` = ?', $sql);
    }

    #[Test]
    public function aggregateWithGroupBy(): void
    {
        $grammar = new MySqlGrammar();
        $sql = $grammar->compileSelect([
            'columns' => [new Expression('COUNT(*) AS `aggregate`')],
            'from' => 'users',
            'groups' => ['role'],
        ]);

        self::assertSame('SELECT COUNT(*) AS `aggregate` FROM `users` GROUP BY `role`', $sql);
    }
}

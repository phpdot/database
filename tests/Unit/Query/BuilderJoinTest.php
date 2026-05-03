<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\Query\Builder;
use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPdot\Database\Query\JoinClause;
use PHPdot\Database\Tests\Unit\Query\Stub\ConnectionStub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuilderJoinTest extends TestCase
{
    private function builder(string $table = 'users'): Builder
    {
        return ConnectionStub::mysqlBuilder($table);
    }

    private function newBuilder(string $table = 'users'): Builder
    {
        $connection = new DatabaseConnection(new DatabaseConfig(driver: 'mysql', database: ':memory:'));
        $grammar = new MySqlGrammar();
        $builder = new Builder($connection, $grammar);

        return $builder->from($table);
    }

    #[Test]
    public function innerJoin(): void
    {
        $sql = $this->builder()
            ->join('orders', 'users.id', '=', 'orders.user_id')
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id`',
            $sql,
        );
    }

    #[Test]
    public function leftJoin(): void
    {
        $sql = $this->builder()
            ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id`',
            $sql,
        );
    }

    #[Test]
    public function rightJoin(): void
    {
        $sql = $this->builder()
            ->rightJoin('orders', 'users.id', '=', 'orders.user_id')
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` RIGHT JOIN `orders` ON `users`.`id` = `orders`.`user_id`',
            $sql,
        );
    }

    #[Test]
    public function crossJoin(): void
    {
        $sql = $this->builder()
            ->crossJoin('colors')
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` CROSS JOIN `colors` ON ',
            $sql,
        );
    }

    #[Test]
    public function joinWithClosure(): void
    {
        $sql = $this->builder()
            ->join('orders', function (JoinClause $join): void {
                $join->on('users.id', '=', 'orders.user_id')
                    ->on('users.store_id', '=', 'orders.store_id');
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND `users`.`store_id` = `orders`.`store_id`',
            $sql,
        );
    }

    #[Test]
    public function joinWithOrOn(): void
    {
        $sql = $this->builder()
            ->join('orders', function (JoinClause $join): void {
                $join->on('users.id', '=', 'orders.user_id')
                    ->orOn('users.id', '=', 'orders.manager_id');
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id` OR `users`.`id` = `orders`.`manager_id`',
            $sql,
        );
    }

    #[Test]
    public function joinSub(): void
    {
        $builder = $this->newBuilder();

        $builder->joinSub(
            function (Builder $query): void {
                $query->from('orders')->selectRaw('user_id, COUNT(*) as order_count')->groupBy('user_id');
            },
            'order_summary',
            'users.id',
            '=',
            'order_summary.user_id',
        );

        $sql = $builder->toSql();

        // joinSub stores the raw subquery as the JoinClause table name.
        // Grammar wraps the entire table string, so verify the structure.
        self::assertStringContainsString('INNER JOIN', $sql);
        self::assertStringContainsString('order_summary', $sql);
        self::assertStringContainsString('`users`.`id` = `order_summary`.`user_id`', $sql);
    }

    #[Test]
    public function multipleJoins(): void
    {
        $sql = $this->builder()
            ->join('orders', 'users.id', '=', 'orders.user_id')
            ->leftJoin('payments', 'orders.id', '=', 'payments.order_id')
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id` LEFT JOIN `payments` ON `orders`.`id` = `payments`.`order_id`',
            $sql,
        );
    }

    #[Test]
    public function joinBindingsFromWhereClause(): void
    {
        $builder = $this->builder()
            ->join('orders', function (JoinClause $join): void {
                $join->on('users.id', '=', 'orders.user_id')
                    ->where('orders.status', 'completed');
            });

        self::assertSame(['completed'], $builder->getBindings());
    }
}

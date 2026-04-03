<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\Connection;
use PHPdot\Database\Query\Builder;
use PHPdot\Database\Query\Expression;
use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPdot\Database\Tests\Unit\Query\Stub\ConnectionStub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuilderSelectTest extends TestCase
{
    private function builder(string $table = 'users'): Builder
    {
        return ConnectionStub::mysqlBuilder($table);
    }

    private function newBuilder(string $table = ''): Builder
    {
        $connection = new Connection(new DatabaseConfig(driver: 'mysql', database: ':memory:'));
        $grammar = new MySqlGrammar();
        $builder = new Builder($connection, $grammar);

        if ($table !== '') {
            $builder->from($table);
        }

        return $builder;
    }

    #[Test]
    public function selectAll(): void
    {
        $sql = $this->builder()->toSql();

        self::assertSame('SELECT * FROM `users`', $sql);
    }

    #[Test]
    public function selectSpecificColumns(): void
    {
        $sql = $this->builder()->select(['name', 'email'])->toSql();

        self::assertSame('SELECT `name`, `email` FROM `users`', $sql);
    }

    #[Test]
    public function addSelectAccumulatesColumns(): void
    {
        $sql = $this->builder()
            ->select(['name'])
            ->addSelect(['email'])
            ->toSql();

        self::assertSame('SELECT `name`, `email` FROM `users`', $sql);
    }

    #[Test]
    public function selectRaw(): void
    {
        $sql = $this->builder()->selectRaw('COUNT(*) as total')->toSql();

        self::assertSame('SELECT COUNT(*) as total FROM `users`', $sql);
    }

    #[Test]
    public function selectSub(): void
    {
        $builder = $this->builder();

        $builder->selectSub(function (Builder $query): void {
            $query->from('orders')->selectRaw('COUNT(*)');
        }, 'order_count');

        $sql = $builder->toSql();

        self::assertSame(
            'SELECT (SELECT COUNT(*) FROM `orders`) AS `order_count` FROM `users`',
            $sql,
        );
    }

    #[Test]
    public function distinct(): void
    {
        $sql = $this->builder()->distinct()->toSql();

        self::assertSame('SELECT DISTINCT * FROM `users`', $sql);
    }

    #[Test]
    public function fromWithAlias(): void
    {
        $sql = $this->newBuilder()->from('users', 'u')->toSql();

        self::assertSame('SELECT * FROM `users` AS `u`', $sql);
    }

    #[Test]
    public function fromSub(): void
    {
        $builder = $this->newBuilder();
        $builder->fromSub(function (Builder $query): void {
            $query->from('orders')->select(['user_id']);
        }, 'sub');

        $sql = $builder->toSql();

        self::assertSame(
            'SELECT * FROM (SELECT `user_id` FROM `orders`) AS `sub`',
            $sql,
        );
    }

    #[Test]
    public function fromRaw(): void
    {
        $sql = $this->newBuilder()->fromRaw('users AS u, orders AS o')->toSql();

        self::assertSame('SELECT * FROM users AS u, orders AS o', $sql);
    }

    #[Test]
    public function selectWithExpression(): void
    {
        $sql = $this->builder()
            ->select([new Expression('COUNT(*) as total')])
            ->toSql();

        self::assertSame('SELECT COUNT(*) as total FROM `users`', $sql);
    }

    #[Test]
    public function selectWithDotNotation(): void
    {
        $sql = $this->builder()->select(['users.name', 'users.email'])->toSql();

        self::assertSame('SELECT `users`.`name`, `users`.`email` FROM `users`', $sql);
    }

    #[Test]
    public function selectWithAlias(): void
    {
        $sql = $this->builder()->select(['name as display_name'])->toSql();

        self::assertSame('SELECT `name` AS `display_name` FROM `users`', $sql);
    }

    #[Test]
    public function orderBy(): void
    {
        $sql = $this->builder()->orderBy('name')->toSql();

        self::assertSame('SELECT * FROM `users` ORDER BY `name` ASC', $sql);
    }

    #[Test]
    public function orderByDesc(): void
    {
        $sql = $this->builder()->orderByDesc('created_at')->toSql();

        self::assertSame('SELECT * FROM `users` ORDER BY `created_at` DESC', $sql);
    }

    #[Test]
    public function orderByRaw(): void
    {
        $sql = $this->builder()->orderByRaw('FIELD(status, "active", "pending")')->toSql();

        self::assertSame('SELECT * FROM `users` ORDER BY FIELD(status, "active", "pending")', $sql);
    }

    #[Test]
    public function latest(): void
    {
        $sql = $this->builder()->latest()->toSql();

        self::assertSame('SELECT * FROM `users` ORDER BY `created_at` DESC', $sql);
    }

    #[Test]
    public function oldest(): void
    {
        $sql = $this->builder()->oldest()->toSql();

        self::assertSame('SELECT * FROM `users` ORDER BY `created_at` ASC', $sql);
    }

    #[Test]
    public function groupBy(): void
    {
        $sql = $this->builder()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->toSql();

        self::assertSame('SELECT status, COUNT(*) as total FROM `users` GROUP BY `status`', $sql);
    }

    #[Test]
    public function having(): void
    {
        $sql = $this->builder()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->having('total', '>', 5)
            ->toSql();

        self::assertSame(
            'SELECT status, COUNT(*) as total FROM `users` GROUP BY `status` HAVING `total` > ?',
            $sql,
        );
    }

    #[Test]
    public function havingRaw(): void
    {
        $sql = $this->builder()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->havingRaw('COUNT(*) > 10')
            ->toSql();

        self::assertSame(
            'SELECT status, COUNT(*) as total FROM `users` GROUP BY `status` HAVING COUNT(*) > 10',
            $sql,
        );
    }

    #[Test]
    public function limitAndOffset(): void
    {
        $sql = $this->builder()->limit(10)->offset(20)->toSql();

        self::assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 20', $sql);
    }

    #[Test]
    public function takeAndSkip(): void
    {
        $sql = $this->builder()->take(5)->skip(10)->toSql();

        self::assertSame('SELECT * FROM `users` LIMIT 5 OFFSET 10', $sql);
    }

    #[Test]
    public function forPage(): void
    {
        $sql = $this->builder()->forPage(3, 15)->toSql();

        self::assertSame('SELECT * FROM `users` LIMIT 15 OFFSET 30', $sql);
    }

    #[Test]
    public function lockForUpdate(): void
    {
        $sql = $this->builder()->lockForUpdate()->toSql();

        self::assertSame('SELECT * FROM `users` FOR UPDATE', $sql);
    }

    #[Test]
    public function sharedLock(): void
    {
        $sql = $this->builder()->sharedLock()->toSql();

        self::assertSame('SELECT * FROM `users` LOCK IN SHARE MODE', $sql);
    }

    #[Test]
    public function union(): void
    {
        $first = $this->builder()->select(['name']);
        $second = $this->newBuilder('admins')->select(['name']);

        $sql = $first->union($second)->toSql();

        self::assertSame(
            'SELECT `name` FROM `users` UNION SELECT `name` FROM `admins`',
            $sql,
        );
    }

    #[Test]
    public function unionAll(): void
    {
        $first = $this->builder()->select(['name']);
        $second = $this->newBuilder('admins')->select(['name']);

        $sql = $first->unionAll($second)->toSql();

        self::assertSame(
            'SELECT `name` FROM `users` UNION ALL SELECT `name` FROM `admins`',
            $sql,
        );
    }

    #[Test]
    public function cte(): void
    {
        $builder = $this->newBuilder();
        $builder->withCte('active_users', function (Builder $query): void {
            $query->from('users')->where('active', 1);
        });
        $builder->from('active_users');

        $sql = $builder->toSql();

        self::assertSame(
            'WITH `active_users` AS (SELECT * FROM `users` WHERE `active` = ?) SELECT * FROM `active_users`',
            $sql,
        );
    }

    #[Test]
    public function recursiveCte(): void
    {
        $builder = $this->newBuilder();
        $builder->withRecursiveCte('tree', function (Builder $query): void {
            $query->from('categories')->where('parent_id', null);
        });
        $builder->from('tree');

        $sql = $builder->toSql();

        self::assertSame(
            'WITH RECURSIVE `tree` AS (SELECT * FROM `categories` WHERE `parent_id` = ?) SELECT * FROM `tree`',
            $sql,
        );
    }

    #[Test]
    public function inRandomOrder(): void
    {
        $sql = $this->builder()->inRandomOrder()->toSql();

        self::assertSame('SELECT * FROM `users` ORDER BY RAND()', $sql);
    }

    #[Test]
    public function reorder(): void
    {
        $sql = $this->builder()->orderBy('name')->reorder()->toSql();

        self::assertSame('SELECT * FROM `users`', $sql);
    }

    #[Test]
    public function whenTrue(): void
    {
        $sql = $this->builder()
            ->when(true, fn(Builder $q): Builder => $q->where('active', 1))
            ->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `active` = ?', $sql);
    }

    #[Test]
    public function whenFalse(): void
    {
        $sql = $this->builder()
            ->when(false, fn(Builder $q): Builder => $q->where('active', 1))
            ->toSql();

        self::assertSame('SELECT * FROM `users`', $sql);
    }

    #[Test]
    public function unlessTrue(): void
    {
        $sql = $this->builder()
            ->unless(true, fn(Builder $q): Builder => $q->where('active', 1))
            ->toSql();

        self::assertSame('SELECT * FROM `users`', $sql);
    }

    #[Test]
    public function unlessFalse(): void
    {
        $sql = $this->builder()
            ->unless(false, fn(Builder $q): Builder => $q->where('active', 1))
            ->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `active` = ?', $sql);
    }

    #[Test]
    public function toRawSql(): void
    {
        $raw = $this->builder()
            ->where('name', 'John')
            ->where('age', '>', 18)
            ->toRawSql();

        self::assertSame("SELECT * FROM `users` WHERE `name` = 'John' AND `age` > 18", $raw);
    }

    #[Test]
    public function getBindings(): void
    {
        $builder = $this->builder()
            ->where('name', 'John')
            ->where('age', '>', 18);

        self::assertSame(['John', 18], $builder->getBindings());
    }
}

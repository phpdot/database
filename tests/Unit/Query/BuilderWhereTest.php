<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Query\Builder;
use PHPdot\Database\Tests\Unit\Query\Stub\ConnectionStub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuilderWhereTest extends TestCase
{
    private function builder(string $table = 'users'): Builder
    {
        return ConnectionStub::mysqlBuilder($table);
    }

    #[Test]
    public function whereWithThreeArgs(): void
    {
        $sql = $this->builder()->where('age', '>', 18)->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `age` > ?', $sql);
    }

    #[Test]
    public function whereWithTwoArgs(): void
    {
        $sql = $this->builder()->where('name', 'John')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `name` = ?', $sql);
    }

    #[Test]
    public function orWhere(): void
    {
        $sql = $this->builder()
            ->where('name', 'John')
            ->orWhere('name', 'Jane')
            ->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `name` = ? OR `name` = ?', $sql);
    }

    #[Test]
    public function whereIn(): void
    {
        $sql = $this->builder()->whereIn('id', [1, 2, 3])->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `id` IN (?, ?, ?)', $sql);
    }

    #[Test]
    public function whereNotIn(): void
    {
        $sql = $this->builder()->whereNotIn('id', [1, 2])->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `id` NOT IN (?, ?)', $sql);
    }

    #[Test]
    public function orWhereIn(): void
    {
        $sql = $this->builder()
            ->where('active', 1)
            ->orWhereIn('role', ['admin', 'editor'])
            ->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `active` = ? OR `role` IN (?, ?)', $sql);
    }

    #[Test]
    public function whereBetween(): void
    {
        $sql = $this->builder()->whereBetween('age', 18, 65)->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `age` BETWEEN ? AND ?', $sql);
    }

    #[Test]
    public function whereNotBetween(): void
    {
        $sql = $this->builder()->whereBetween('age', 18, 65, true)->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `age` NOT BETWEEN ? AND ?', $sql);
    }

    #[Test]
    public function whereNull(): void
    {
        $sql = $this->builder()->whereNull('deleted_at')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL', $sql);
    }

    #[Test]
    public function whereNotNull(): void
    {
        $sql = $this->builder()->whereNotNull('email_verified_at')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `email_verified_at` IS NOT NULL', $sql);
    }

    #[Test]
    public function whereExists(): void
    {
        $sql = $this->builder()->whereExists(function (Builder $query): void {
            $query->from('orders')->selectRaw('1')->where('orders.user_id', 'users.id');
        })->toSql();

        self::assertSame(
            'SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM `orders` WHERE `orders`.`user_id` = ?)',
            $sql,
        );
    }

    #[Test]
    public function whereColumn(): void
    {
        $sql = $this->builder()->whereColumn('updated_at', '>', 'created_at')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `updated_at` > `created_at`', $sql);
    }

    #[Test]
    public function whereRaw(): void
    {
        $sql = $this->builder()->whereRaw('YEAR(created_at) = ?', [2024])->toSql();

        self::assertSame('SELECT * FROM `users` WHERE YEAR(created_at) = ?', $sql);
        self::assertSame([2024], $this->builder()->whereRaw('YEAR(created_at) = ?', [2024])->getBindings());
    }

    #[Test]
    public function orWhereRaw(): void
    {
        $sql = $this->builder()
            ->where('active', 1)
            ->orWhereRaw('score > 100')
            ->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `active` = ? OR score > 100', $sql);
    }

    #[Test]
    public function whereDate(): void
    {
        $sql = $this->builder()->whereDate('created_at', '=', '2024-01-01')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE DATE(`created_at`) = ?', $sql);
    }

    #[Test]
    public function whereMonth(): void
    {
        $sql = $this->builder()->whereMonth('created_at', '=', '12')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE MONTH(`created_at`) = ?', $sql);
    }

    #[Test]
    public function whereYear(): void
    {
        $sql = $this->builder()->whereYear('created_at', '=', '2024')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE YEAR(`created_at`) = ?', $sql);
    }

    #[Test]
    public function whereDay(): void
    {
        $sql = $this->builder()->whereDay('created_at', '=', '15')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE DAY(`created_at`) = ?', $sql);
    }

    #[Test]
    public function whereTime(): void
    {
        $sql = $this->builder()->whereTime('created_at', '>', '10:00:00')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE TIME(`created_at`) > ?', $sql);
    }

    #[Test]
    public function whereJsonContains(): void
    {
        $sql = $this->builder()->whereJsonContains('options->languages', 'en')->toSql();

        self::assertSame(
            "SELECT * FROM `users` WHERE JSON_CONTAINS(`options`->'\$.languages', ?)",
            $sql,
        );
    }

    #[Test]
    public function whereJsonLength(): void
    {
        $sql = $this->builder()->whereJsonLength('options', '>', 0)->toSql();

        self::assertSame('SELECT * FROM `users` WHERE JSON_LENGTH(`options`) > ?', $sql);
    }

    #[Test]
    public function whereLike(): void
    {
        $sql = $this->builder()->whereLike('name', '%john%')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `name` LIKE ?', $sql);
    }

    #[Test]
    public function whereNotLike(): void
    {
        $sql = $this->builder()->whereNotLike('name', '%admin%')->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `name` NOT LIKE ?', $sql);
    }

    #[Test]
    public function multipleWheresChainWithAnd(): void
    {
        $sql = $this->builder()
            ->where('active', 1)
            ->where('role', 'admin')
            ->where('age', '>', 21)
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` WHERE `active` = ? AND `role` = ? AND `age` > ?',
            $sql,
        );
    }

    #[Test]
    public function nestedWhereWithClosure(): void
    {
        $sql = $this->builder()
            ->where('active', 1)
            ->where(function (Builder $query): void {
                $query->where('role', 'admin')->orWhere('role', 'editor');
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` WHERE `active` = ? AND (`role` = ? OR `role` = ?)',
            $sql,
        );
    }

    #[Test]
    public function orWhereNestedWithClosure(): void
    {
        $sql = $this->builder()
            ->where('active', 1)
            ->orWhere(function (Builder $query): void {
                $query->where('role', 'admin')->where('age', '>', 21);
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users` WHERE `active` = ? OR (`role` = ? AND `age` > ?)',
            $sql,
        );
    }

    #[Test]
    public function whereSub(): void
    {
        $sql = $this->builder()->whereSub('age', '>', function (Builder $query): void {
            $query->from('settings')->selectRaw('min_age');
        })->toSql();

        self::assertSame(
            'SELECT * FROM `users` WHERE `age` > (SELECT min_age FROM `settings`)',
            $sql,
        );
    }

    #[Test]
    public function whereAll(): void
    {
        $sql = $this->builder()->whereAll([
            ['name', 'John'],
            ['age', '>', 18],
        ])->toSql();

        self::assertSame('SELECT * FROM `users` WHERE `name` = ? AND `age` > ?', $sql);
    }

    #[Test]
    public function whereFullText(): void
    {
        $sql = $this->builder()->whereFullText(['title', 'body'], 'search term')->toSql();

        self::assertSame(
            'SELECT * FROM `users` WHERE MATCH(`title`, `body`) AGAINST(?)',
            $sql,
        );
    }

    #[Test]
    public function whereInBindings(): void
    {
        $builder = $this->builder()->whereIn('id', [10, 20, 30]);

        self::assertSame([10, 20, 30], $builder->getBindings());
    }

    #[Test]
    public function whereBetweenBindings(): void
    {
        $builder = $this->builder()->whereBetween('age', 18, 65);

        self::assertSame([18, 65], $builder->getBindings());
    }
}

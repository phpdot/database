<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Query\JoinClause;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JoinClauseTest extends TestCase
{
    #[Test]
    public function onStoresClause(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->on('users.id', '=', 'orders.user_id');

        self::assertCount(1, $join->clauses);
        self::assertSame('on', $join->clauses[0]['type']);
        self::assertSame('users.id', $join->clauses[0]['first']);
        self::assertSame('=', $join->clauses[0]['operator']);
        self::assertSame('orders.user_id', $join->clauses[0]['second']);
        self::assertSame('and', $join->clauses[0]['boolean']);
    }

    #[Test]
    public function orOnStoresWithBooleanOr(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->on('users.id', '=', 'orders.user_id')
            ->orOn('users.id', '=', 'orders.manager_id');

        self::assertCount(2, $join->clauses);
        self::assertSame('and', $join->clauses[0]['boolean']);
        self::assertSame('or', $join->clauses[1]['boolean']);
    }

    #[Test]
    public function whereInJoin(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->on('users.id', '=', 'orders.user_id')
            ->where('orders.status', 'completed');

        self::assertCount(2, $join->clauses);
        self::assertSame('where', $join->clauses[1]['type']);
        self::assertSame('orders.status', $join->clauses[1]['column']);
        self::assertSame('=', $join->clauses[1]['operator']);
        self::assertSame('?', $join->clauses[1]['value']);
        self::assertSame(['completed'], $join->bindings);
    }

    #[Test]
    public function whereWithThreeArgs(): void
    {
        $join = new JoinClause('left', 'orders');
        $join->where('orders.amount', '>', 100);

        self::assertSame('>', $join->clauses[0]['operator']);
        self::assertSame([100], $join->bindings);
    }

    #[Test]
    public function orWhereInJoin(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->where('orders.status', 'completed')
            ->orWhere('orders.status', 'pending');

        self::assertSame('and', $join->clauses[0]['boolean']);
        self::assertSame('or', $join->clauses[1]['boolean']);
        self::assertSame(['completed', 'pending'], $join->bindings);
    }

    #[Test]
    public function whereNullInJoin(): void
    {
        $join = new JoinClause('left', 'orders');
        $join->on('users.id', '=', 'orders.user_id')
            ->whereNull('orders.deleted_at');

        self::assertSame('null', $join->clauses[1]['type']);
        self::assertSame('orders.deleted_at', $join->clauses[1]['column']);
        self::assertSame('and', $join->clauses[1]['boolean']);
    }

    #[Test]
    public function whereNotNullInJoin(): void
    {
        $join = new JoinClause('left', 'orders');
        $join->whereNotNull('orders.confirmed_at');

        self::assertSame('notNull', $join->clauses[0]['type']);
        self::assertSame('orders.confirmed_at', $join->clauses[0]['column']);
    }

    #[Test]
    public function whereInValues(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->whereIn('orders.status', ['completed', 'pending']);

        self::assertSame('in', $join->clauses[0]['type']);
        self::assertSame(['?', '?'], $join->clauses[0]['values']);
        self::assertSame(['completed', 'pending'], $join->bindings);
    }

    #[Test]
    public function whereNotInValues(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->whereNotIn('orders.status', ['cancelled']);

        self::assertSame('notIn', $join->clauses[0]['type']);
        self::assertSame(['?'], $join->clauses[0]['values']);
        self::assertSame(['cancelled'], $join->bindings);
    }

    #[Test]
    public function whereColumnInJoin(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->whereColumn('users.store_id', '=', 'orders.store_id');

        self::assertSame('column', $join->clauses[0]['type']);
        self::assertSame('users.store_id', $join->clauses[0]['first']);
        self::assertSame('orders.store_id', $join->clauses[0]['second']);
    }

    #[Test]
    public function whereRawInJoin(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->whereRaw('orders.amount > ?', [100]);

        self::assertSame('raw', $join->clauses[0]['type']);
        self::assertSame('orders.amount > ?', $join->clauses[0]['sql']);
        self::assertSame([100], $join->bindings);
    }

    #[Test]
    public function bindingsAccumulated(): void
    {
        $join = new JoinClause('inner', 'orders');
        $join->on('users.id', '=', 'orders.user_id')
            ->where('orders.status', 'completed')
            ->where('orders.amount', '>', 50);

        self::assertSame(['completed', 50], $join->bindings);
    }

    #[Test]
    public function constructorSetsTypeAndTable(): void
    {
        $join = new JoinClause('left', 'payments');

        self::assertSame('left', $join->type);
        self::assertSame('payments', $join->table);
        self::assertSame([], $join->clauses);
        self::assertSame([], $join->bindings);
    }

    #[Test]
    public function fluentChaining(): void
    {
        $join = new JoinClause('inner', 'orders');
        $result = $join->on('users.id', '=', 'orders.user_id')
            ->where('orders.status', 'active')
            ->whereNull('orders.deleted_at');

        self::assertSame($join, $result);
        self::assertCount(3, $join->clauses);
    }
}

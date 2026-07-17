<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query;

use PHPdot\Database\Query\Expression;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExpressionTest extends TestCase
{
    #[Test]
    public function constructorStoresValue(): void
    {
        $expression = new Expression('COUNT(*)');

        self::assertSame('COUNT(*)', $expression->value);
    }

    #[Test]
    public function toStringReturnsValue(): void
    {
        $expression = new Expression('NOW()');

        self::assertSame('NOW()', $expression->__toString());
    }

    #[Test]
    public function canBeUsedInStringContext(): void
    {
        $expression = new Expression('SUM(amount)');

        self::assertSame('SELECT SUM(amount)', 'SELECT ' . $expression);
    }
}

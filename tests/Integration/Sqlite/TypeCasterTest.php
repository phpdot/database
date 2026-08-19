<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\Sqlite;

use PHPdot\Database\Result\TypeCaster;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypeCasterTest extends TestCase
{
    #[Test]
    public function castInt(): void
    {
        $caster = new TypeCaster(['age' => 'int']);

        $result = $caster->cast(['age' => '25']);

        self::assertSame(25, $result['age']);
    }

    #[Test]
    public function castFloat(): void
    {
        $caster = new TypeCaster(['balance' => 'float']);

        $result = $caster->cast(['balance' => '100.50']);

        self::assertSame(100.50, $result['balance']);
    }

    #[Test]
    public function castBool(): void
    {
        $caster = new TypeCaster(['active' => 'bool']);

        $result = $caster->cast(['active' => '1']);

        self::assertTrue($result['active']);

        $result = $caster->cast(['active' => '0']);

        self::assertFalse($result['active']);
    }

    #[Test]
    public function castString(): void
    {
        $caster = new TypeCaster(['id' => 'string']);

        $result = $caster->cast(['id' => 42]);

        self::assertSame('42', $result['id']);
    }

    #[Test]
    public function castJson(): void
    {
        $caster = new TypeCaster(['data' => 'json']);

        $result = $caster->cast(['data' => '{"key":"value"}']);

        self::assertSame(['key' => 'value'], $result['data']);
    }

    #[Test]
    public function castDatetime(): void
    {
        $caster = new TypeCaster(['created_at' => 'datetime']);

        $result = $caster->cast(['created_at' => '2026-04-03 12:00:00']);

        self::assertSame('2026-04-03 12:00:00', $result['created_at']);
    }
}

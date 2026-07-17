<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\Sqlite;

use PHPdot\Database\Result\TypeCaster;
use PHPUnit\Framework\TestCase;

final class TypeCasterTest extends TestCase
{
    public function testCastInt(): void
    {
        $caster = new TypeCaster(['age' => 'int']);

        $result = $caster->cast(['age' => '25']);

        self::assertSame(25, $result['age']);
    }

    public function testCastFloat(): void
    {
        $caster = new TypeCaster(['balance' => 'float']);

        $result = $caster->cast(['balance' => '100.50']);

        self::assertSame(100.50, $result['balance']);
    }

    public function testCastBool(): void
    {
        $caster = new TypeCaster(['active' => 'bool']);

        $result = $caster->cast(['active' => '1']);

        self::assertTrue($result['active']);

        $result = $caster->cast(['active' => '0']);

        self::assertFalse($result['active']);
    }

    public function testCastString(): void
    {
        $caster = new TypeCaster(['id' => 'string']);

        $result = $caster->cast(['id' => 42]);

        self::assertSame('42', $result['id']);
    }

    public function testCastJson(): void
    {
        $caster = new TypeCaster(['data' => 'json']);

        $result = $caster->cast(['data' => '{"key":"value"}']);

        self::assertSame(['key' => 'value'], $result['data']);
    }

    public function testCastDatetime(): void
    {
        $caster = new TypeCaster(['created_at' => 'datetime']);

        $result = $caster->cast(['created_at' => '2026-04-03 12:00:00']);

        self::assertSame('2026-04-03 12:00:00', $result['created_at']);
    }
}

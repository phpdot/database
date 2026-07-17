<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Connection;

use InvalidArgumentException;
use PHPdot\Database\Connection\ConfigValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigValueTest extends TestCase
{
    #[Test]
    public function stringCoercesScalarsAndFallsBackOnNonScalars(): void
    {
        self::assertSame('h', ConfigValue::string(['k' => 'h'], 'k', 'd'));
        self::assertSame('123', ConfigValue::string(['k' => 123], 'k', 'd'));
        self::assertSame('d', ConfigValue::string(['k' => ['x']], 'k', 'd'));
        self::assertSame('d', ConfigValue::string([], 'k', 'd'));
    }

    #[Test]
    public function intCoercesNumericsAndFallsBackOnNonNumerics(): void
    {
        self::assertSame(3307, ConfigValue::int(['p' => '3307'], 'p', 1));
        self::assertSame(5, ConfigValue::int(['p' => 5], 'p', 1));
        self::assertSame(9, ConfigValue::int(['p' => [1]], 'p', 9));
        self::assertSame(9, ConfigValue::int(['p' => 'abc'], 'p', 9));
        self::assertSame(9, ConfigValue::int([], 'p', 9));
    }

    #[Test]
    public function boolAcceptsNativeBooleans(): void
    {
        self::assertTrue(ConfigValue::bool(['x' => true], 'x', false));
        self::assertFalse(ConfigValue::bool(['x' => false], 'x', true));
    }

    #[Test]
    public function boolParsesFalsyStringForms(): void
    {
        self::assertFalse(ConfigValue::bool(['x' => 'false'], 'x', true));
        self::assertFalse(ConfigValue::bool(['x' => 'off'], 'x', true));
        self::assertFalse(ConfigValue::bool(['x' => 'no'], 'x', true));
        self::assertFalse(ConfigValue::bool(['x' => '0'], 'x', true));
    }

    #[Test]
    public function boolParsesTruthyStringForms(): void
    {
        self::assertTrue(ConfigValue::bool(['x' => 'true'], 'x', false));
        self::assertTrue(ConfigValue::bool(['x' => 'on'], 'x', false));
        self::assertTrue(ConfigValue::bool(['x' => 'yes'], 'x', false));
        self::assertTrue(ConfigValue::bool(['x' => '1'], 'x', false));
    }

    #[Test]
    public function boolFallsBackOnNonScalarsAndUnparseableStrings(): void
    {
        self::assertTrue(ConfigValue::bool(['x' => ['a']], 'x', true));
        self::assertFalse(ConfigValue::bool(['x' => 'weird'], 'x', false));
        self::assertTrue(ConfigValue::bool([], 'x', true));
    }

    #[Test]
    public function requireStringReturnsThePresentValue(): void
    {
        self::assertSame('app', ConfigValue::requireString('main', 'mysql', ['database' => 'app'], 'database'));
    }

    #[Test]
    public function requireStringThrowsNamingConnectionAndKeyWhenMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Database connection 'cache' (sqlite) requires a non-empty 'database'.");

        ConfigValue::requireString('cache', 'sqlite', [], 'database');
    }

    #[Test]
    public function requireStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConfigValue::requireString('cache', 'sqlite', ['database' => ''], 'database');
    }

    #[Test]
    public function replicasReturnsEmptyWhenReadIsAbsent(): void
    {
        self::assertSame([], ConfigValue::replicas('main', []));
    }

    #[Test]
    public function replicasReturnsWellFormedOverrideBlocks(): void
    {
        $read = [['host' => 'r1'], ['host' => 'r2', 'port' => 3307]];

        self::assertSame($read, ConfigValue::replicas('main', ['read' => $read]));
    }

    #[Test]
    public function replicasThrowsWhenReadIsNotAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Database connection 'main': 'read' must be a list of replica override blocks.");

        ConfigValue::replicas('main', ['read' => 'replica-host']);
    }

    #[Test]
    public function replicasThrowsWhenReadIsAKeyedMap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConfigValue::replicas('main', ['read' => ['a' => ['host' => 'r1']]]);
    }

    #[Test]
    public function replicasThrowsWhenAnEntryIsNotAnArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Database connection 'main': each 'read' entry must be an array of connection overrides.");

        ConfigValue::replicas('main', ['read' => ['replica-host']]);
    }

    #[Test]
    public function driverOptionsReturnsArraysAndDiscardsNonArrays(): void
    {
        self::assertSame(['timeout' => 10], ConfigValue::driverOptions(['options' => ['timeout' => 10]]));
        self::assertSame([], ConfigValue::driverOptions(['options' => 'x']));
        self::assertSame([], ConfigValue::driverOptions([]));
    }
}

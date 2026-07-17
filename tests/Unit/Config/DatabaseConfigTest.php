<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Config;

use PHPdot\Database\Config\DatabaseConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    #[Test]
    public function shipsASingleDefaultMysqlConnection(): void
    {
        $config = new DatabaseConfig();

        self::assertSame('default', $config->default);
        self::assertSame(['default'], $config->names());
        self::assertTrue($config->has('default'));
        self::assertSame('mysql', $config->connections['default']['driver']);
    }

    #[Test]
    public function exposesNamedConnectionsOfMixedDrivers(): void
    {
        $config = new DatabaseConfig(
            default: 'main',
            connections: [
                'main' => ['driver' => 'mysql', 'database' => 'app'],
                'cache' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        );

        self::assertSame('main', $config->default);
        self::assertSame(['main', 'cache'], $config->names());
        self::assertTrue($config->has('main'));
        self::assertTrue($config->has('cache'));
        self::assertFalse($config->has('missing'));
    }
}

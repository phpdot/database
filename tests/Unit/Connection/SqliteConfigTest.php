<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Connection;

use InvalidArgumentException;
use PHPdot\Database\Connection\Sqlite\SqliteConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqliteConfigTest extends TestCase
{
    #[Test]
    public function fromArrayRequiresADatabasePath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SqliteConfig::fromArray('cache', ['driver' => 'sqlite']);
    }

    #[Test]
    public function dbalParamsUseThePath(): void
    {
        $config = SqliteConfig::fromArray('cache', ['driver' => 'sqlite', 'database' => '/tmp/app.sqlite']);
        $params = $config->dbalParams();

        self::assertSame('pdo_sqlite', $params['driver']);
        self::assertSame('/tmp/app.sqlite', $params['path']);
        self::assertSame('sqlite', $config->driver());
        self::assertSame('/tmp/app.sqlite', $config->database());
    }

    #[Test]
    public function hasNoReadReplicas(): void
    {
        $config = new SqliteConfig(database: ':memory:');

        self::assertNull($config->readReplicaParams());
    }
}

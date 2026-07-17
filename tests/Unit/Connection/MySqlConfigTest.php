<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Connection;

use InvalidArgumentException;
use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MySqlConfigTest extends TestCase
{
    #[Test]
    public function fromArrayRequiresADatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MySqlConfig::fromArray('main', ['driver' => 'mysql']);
    }

    #[Test]
    public function dbalParamsApplyMysqlDefaults(): void
    {
        $config = MySqlConfig::fromArray('main', ['driver' => 'mysql', 'database' => 'app']);
        $params = $config->dbalParams();

        self::assertSame('pdo_mysql', $params['driver']);
        self::assertSame('app', $params['dbname']);
        self::assertSame(3306, $params['port']);
        self::assertSame('utf8mb4', $params['charset']);
    }

    #[Test]
    public function readReplicaParamsOverrideHostButInheritDatabase(): void
    {
        $config = MySqlConfig::fromArray('main', [
            'driver' => 'mysql',
            'database' => 'app',
            'read' => [['host' => 'replica-1']],
        ]);

        $params = $config->readReplicaParams();

        self::assertNotNull($params);
        self::assertSame('replica-1', $params['host']);
        self::assertSame('app', $params['dbname']);
    }

    #[Test]
    public function noReplicasReturnsNull(): void
    {
        $config = MySqlConfig::fromArray('main', ['driver' => 'mysql', 'database' => 'app']);

        self::assertNull($config->readReplicaParams());
    }
}

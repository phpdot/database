<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Connection;

use PHPdot\Database\Connection\ConnectionFactory;
use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\Connection\Postgres\PostgresConfig;
use PHPdot\Database\DatabaseConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReplicaParamsTest extends TestCase
{
    #[Test]
    public function mysqlWithoutReplicasReturnsNull(): void
    {
        $config = MySqlConfig::fromArray('main', ['driver' => 'mysql', 'database' => 'app']);

        self::assertNull($config->readReplicaParams());
    }

    #[Test]
    public function postgresWithoutReplicasReturnsNull(): void
    {
        $config = PostgresConfig::fromArray('main', ['driver' => 'pgsql', 'database' => 'app']);

        self::assertNull($config->readReplicaParams());
    }

    #[Test]
    public function mysqlReplicaOverridesHostAndInheritsDatabase(): void
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
        self::assertSame(3306, $params['port']);
    }

    #[Test]
    public function mysqlReplicaCharsetOverrideIsHonored(): void
    {
        $config = MySqlConfig::fromArray('main', [
            'driver' => 'mysql',
            'database' => 'app',
            'read' => [['host' => 'replica-1', 'charset' => 'latin1']],
        ]);

        $params = $config->readReplicaParams();

        self::assertNotNull($params);
        self::assertSame('latin1', $params['charset']);
    }

    #[Test]
    public function postgresReplicaCharsetOverrideIsHonored(): void
    {
        $config = PostgresConfig::fromArray('main', [
            'driver' => 'pgsql',
            'database' => 'app',
            'read' => [['charset' => 'latin1']],
        ]);

        $params = $config->readReplicaParams();

        self::assertNotNull($params);
        self::assertSame('latin1', $params['charset']);
    }

    #[Test]
    public function postgresReplicaNormalisesMysqlCharsetAway(): void
    {
        $config = PostgresConfig::fromArray('main', [
            'driver' => 'pgsql',
            'database' => 'app',
            'read' => [['host' => 'replica-1', 'charset' => 'utf8mb4']],
        ]);

        $params = $config->readReplicaParams();

        self::assertNotNull($params);
        self::assertSame('utf8', $params['charset']);
        self::assertSame('replica-1', $params['host']);
        self::assertSame('app', $params['dbname']);
    }

    #[Test]
    public function factoryBuildsAConnectorFromABlock(): void
    {
        $connector = (new ConnectionFactory())->connector('cache', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        self::assertInstanceOf(DatabaseConnector::class, $connector);
    }
}

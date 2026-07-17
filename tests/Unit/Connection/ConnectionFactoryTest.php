<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Connection;

use InvalidArgumentException;
use PHPdot\Database\Connection\ConnectionFactory;
use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\Connection\Postgres\PostgresConfig;
use PHPdot\Database\Connection\Sqlite\SqliteConfig;
use PHPdot\Database\DatabaseConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    private ConnectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ConnectionFactory();
    }

    #[Test]
    public function resolvesMysqlBlockToMySqlConfig(): void
    {
        $config = $this->factory->config('main', ['driver' => 'mysql', 'database' => 'app']);

        self::assertInstanceOf(MySqlConfig::class, $config);
        self::assertSame('mysql', $config->driver());
    }

    #[Test]
    public function resolvesSqliteBlockToSqliteConfig(): void
    {
        $config = $this->factory->config('cache', ['driver' => 'sqlite', 'database' => ':memory:']);

        self::assertInstanceOf(SqliteConfig::class, $config);
        self::assertSame('sqlite', $config->driver());
    }

    #[Test]
    public function resolvesPgsqlBlockToPostgresConfig(): void
    {
        $config = $this->factory->config('reports', ['driver' => 'pgsql', 'database' => 'app']);

        self::assertInstanceOf(PostgresConfig::class, $config);
        self::assertSame('pgsql', $config->driver());
    }

    #[Test]
    public function missingDriverThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory->config('cache', ['database' => 'app']);
    }

    #[Test]
    public function unsupportedDriverThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory->config('cache', ['driver' => 'oracle', 'database' => 'app']);
    }

    #[Test]
    public function buildsAConnectionFromABlock(): void
    {
        $connection = $this->factory->connection('cache', ['driver' => 'sqlite', 'database' => ':memory:']);

        self::assertInstanceOf(DatabaseConnection::class, $connection);
        self::assertSame('sqlite', $connection->getDriverName());
    }
}

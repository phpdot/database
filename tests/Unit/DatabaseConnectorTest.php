<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit;

use PHPdot\Contracts\Pool\ConnectorInterface;
use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\DatabaseConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DatabaseConnectorTest extends TestCase
{
    private DatabaseConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new DatabaseConnector(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));
    }

    #[Test]
    public function it_implements_the_pool_connector_contract(): void
    {
        self::assertInstanceOf(ConnectorInterface::class, $this->connector);
    }

    #[Test]
    public function connect_returns_an_initialised_connection(): void
    {
        $connection = $this->connector->connect();

        self::assertInstanceOf(DatabaseConnection::class, $connection);
        self::assertTrue($connection->isConnected());

        $this->connector->close($connection);
    }

    #[Test]
    public function is_alive_returns_true_for_a_live_connection(): void
    {
        $connection = $this->connector->connect();

        self::assertTrue($this->connector->isAlive($connection));

        $this->connector->close($connection);
    }

    #[Test]
    public function is_alive_returns_false_after_close(): void
    {
        $connection = $this->connector->connect();
        $this->connector->close($connection);

        self::assertFalse($this->connector->isAlive($connection));
    }

    #[Test]
    public function is_alive_returns_false_for_non_connection_objects(): void
    {
        self::assertFalse($this->connector->isAlive(new stdClass()));
    }

    #[Test]
    public function close_is_safe_on_non_connection_objects(): void
    {
        $this->connector->close(new stdClass());

        // Reaching this line means no exception was thrown.
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $connection = $this->connector->connect();
        $this->connector->close($connection);
        $this->connector->close($connection);

        $this->expectNotToPerformAssertions();
    }
}

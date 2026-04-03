<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Config;

use PHPdot\Database\Config\DatabaseConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    #[Test]
    public function defaultDriverIsMysql(): void
    {
        $config = new DatabaseConfig();

        self::assertSame('mysql', $config->driver);
    }

    #[Test]
    public function defaultHostIsLocalhost(): void
    {
        $config = new DatabaseConfig();

        self::assertSame('localhost', $config->host);
    }

    #[Test]
    public function defaultPortIs3306(): void
    {
        $config = new DatabaseConfig();

        self::assertSame(3306, $config->port);
    }

    #[Test]
    public function defaultDatabaseIsEmptyString(): void
    {
        $config = new DatabaseConfig();

        self::assertSame('', $config->database);
    }

    #[Test]
    public function defaultUsernameIsRoot(): void
    {
        $config = new DatabaseConfig();

        self::assertSame('root', $config->username);
    }

    #[Test]
    public function defaultPasswordIsEmptyString(): void
    {
        $config = new DatabaseConfig();

        self::assertSame('', $config->password);
    }

    #[Test]
    public function defaultCharsetIsUtf8mb4(): void
    {
        $config = new DatabaseConfig();

        self::assertSame('utf8mb4', $config->charset);
    }

    #[Test]
    public function defaultPrefixIsEmptyString(): void
    {
        $config = new DatabaseConfig();

        self::assertSame('', $config->prefix);
    }

    #[Test]
    public function defaultReadIsEmptyArray(): void
    {
        $config = new DatabaseConfig();

        self::assertSame([], $config->read);
    }

    #[Test]
    public function defaultStickyIsTrue(): void
    {
        $config = new DatabaseConfig();

        self::assertTrue($config->sticky);
    }

    #[Test]
    public function defaultMaxRetriesIsThree(): void
    {
        $config = new DatabaseConfig();

        self::assertSame(3, $config->maxRetries);
    }

    #[Test]
    public function defaultRetryDelayMsIs200(): void
    {
        $config = new DatabaseConfig();

        self::assertSame(200, $config->retryDelayMs);
    }

    #[Test]
    public function defaultSlowQueryThresholdIs100(): void
    {
        $config = new DatabaseConfig();

        self::assertSame(100, $config->slowQueryThreshold);
    }

    #[Test]
    public function defaultOptionsIsEmptyArray(): void
    {
        $config = new DatabaseConfig();

        self::assertSame([], $config->options);
    }

    #[Test]
    public function customValuesAreStoredCorrectly(): void
    {
        $config = new DatabaseConfig(
            driver: 'pgsql',
            host: '10.0.0.1',
            port: 5432,
            database: 'app_db',
            username: 'admin',
            password: 'secret',
            charset: 'utf8',
            prefix: 'app_',
            read: [['host' => '10.0.0.2']],
            sticky: false,
            maxRetries: 5,
            retryDelayMs: 500,
            slowQueryThreshold: 250,
            options: ['timeout' => 10],
        );

        self::assertSame('pgsql', $config->driver);
        self::assertSame('10.0.0.1', $config->host);
        self::assertSame(5432, $config->port);
        self::assertSame('app_db', $config->database);
        self::assertSame('admin', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame('utf8', $config->charset);
        self::assertSame('app_', $config->prefix);
        self::assertSame([['host' => '10.0.0.2']], $config->read);
        self::assertFalse($config->sticky);
        self::assertSame(5, $config->maxRetries);
        self::assertSame(500, $config->retryDelayMs);
        self::assertSame(250, $config->slowQueryThreshold);
        self::assertSame(['timeout' => 10], $config->options);
    }
}

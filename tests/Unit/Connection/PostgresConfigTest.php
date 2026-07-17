<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Connection;

use InvalidArgumentException;
use PHPdot\Database\Connection\Postgres\PostgresConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostgresConfigTest extends TestCase
{
    #[Test]
    public function fromArrayRequiresADatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PostgresConfig::fromArray('reports', ['driver' => 'pgsql']);
    }

    #[Test]
    public function dbalParamsApplyPostgresDefaults(): void
    {
        $config = PostgresConfig::fromArray('reports', ['driver' => 'pgsql', 'database' => 'app']);
        $params = $config->dbalParams();

        self::assertSame('pdo_pgsql', $params['driver']);
        self::assertSame(5432, $params['port']);
        self::assertSame('utf8', $params['charset']);
    }

    #[Test]
    public function mysqlCharsetIsNormalisedAway(): void
    {
        $config = PostgresConfig::fromArray('reports', [
            'driver' => 'pgsql',
            'database' => 'app',
            'charset' => 'utf8mb4',
        ]);

        self::assertSame('utf8', $config->dbalParams()['charset']);
    }

    #[Test]
    public function gssencmodeDefaultsOffOnMacosAndIsAbsentElsewhere(): void
    {
        $params = PostgresConfig::fromArray('reports', ['driver' => 'pgsql', 'database' => 'app'])->dbalParams();

        // libpq's gssencmode=prefer default gets a forked macOS worker
        // SIGKILLed (EXC_GUARD via the Kerberos/CFPreferences probe).
        if (PHP_OS_FAMILY === 'Darwin') {
            self::assertSame('disable', $params['gssencmode'] ?? null);
        } else {
            self::assertArrayNotHasKey('gssencmode', $params);
        }
    }

    #[Test]
    public function explicitGssencmodeIsRespected(): void
    {
        $params = PostgresConfig::fromArray('reports', [
            'driver' => 'pgsql',
            'database' => 'app',
            'gssencmode' => 'prefer',
        ])->dbalParams();

        self::assertSame('prefer', $params['gssencmode'] ?? null);
    }
}

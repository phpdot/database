<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Connection;

use InvalidArgumentException;
use PHPdot\Database\Connection\ConnectionOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionOptionsTest extends TestCase
{
    #[Test]
    public function defaultsMatchTheDocumentedBehaviour(): void
    {
        $options = new ConnectionOptions();

        self::assertSame('', $options->prefix);
        self::assertSame([], $options->read);
        self::assertTrue($options->sticky);
        self::assertSame(3, $options->maxRetries);
        self::assertSame(200, $options->retryDelayMs);
        self::assertSame(100, $options->slowQueryThreshold);
    }

    #[Test]
    public function fromArrayAppliesTheSameDefaultsForAnEmptyBlock(): void
    {
        $options = ConnectionOptions::fromArray('main', []);

        self::assertSame('', $options->prefix);
        self::assertSame([], $options->read);
        self::assertTrue($options->sticky);
        self::assertSame(3, $options->maxRetries);
        self::assertSame(200, $options->retryDelayMs);
        self::assertSame(100, $options->slowQueryThreshold);
    }

    #[Test]
    public function fromArrayCoercesBlockValues(): void
    {
        $options = ConnectionOptions::fromArray('main', [
            'prefix' => 'app_',
            'read' => [['host' => 'r1']],
            'sticky' => 'false',
            'maxRetries' => '5',
            'retryDelayMs' => '500',
            'slowQueryThreshold' => '250',
        ]);

        self::assertSame('app_', $options->prefix);
        self::assertSame([['host' => 'r1']], $options->read);
        self::assertFalse($options->sticky);
        self::assertSame(5, $options->maxRetries);
        self::assertSame(500, $options->retryDelayMs);
        self::assertSame(250, $options->slowQueryThreshold);
    }

    #[Test]
    public function maxRetriesBelowOneThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRetries must be at least 1, got 0.');

        new ConnectionOptions(maxRetries: 0);
    }

    #[Test]
    public function negativeRetryDelayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retryDelayMs must not be negative, got -5.');

        new ConnectionOptions(retryDelayMs: -5);
    }

    #[Test]
    public function negativeSlowQueryThresholdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('slowQueryThreshold must not be negative, got -1.');

        new ConnectionOptions(slowQueryThreshold: -1);
    }

    #[Test]
    public function fromArrayRejectsOutOfRangeBlockValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConnectionOptions::fromArray('main', ['maxRetries' => 0]);
    }
}

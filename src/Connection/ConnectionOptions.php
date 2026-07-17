<?php

declare(strict_types=1);

/**
 * Driver-agnostic per-connection behaviour shared by every driver: table
 * prefix, read replicas, write stickiness, reconnection retries, and the slow
 * query threshold. The driver-specific connection parameters live on the
 * per-driver ConnectionConfig implementations instead.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Connection;

use InvalidArgumentException;

final readonly class ConnectionOptions
{
    /**
     * Capture the shared per-connection behaviour options.
     *
     * @param string $prefix Table name prefix
     * @param list<array<string, mixed>> $read Read replica override blocks
     * @param bool $sticky Stick to the write connection after a write
     * @param int $maxRetries Reconnection attempts (must be at least 1)
     * @param int $retryDelayMs Base delay between retries in milliseconds (must not be negative)
     * @param int $slowQueryThreshold Slow query warning threshold in milliseconds (must not be negative)
     *
     * @throws InvalidArgumentException When a numeric bound is out of range
     */
    public function __construct(
        public string $prefix = '',
        public array $read = [],
        public bool $sticky = true,
        public int $maxRetries = 3,
        public int $retryDelayMs = 200,
        public int $slowQueryThreshold = 100,
    ) {
        if ($maxRetries < 1) {
            throw new InvalidArgumentException("maxRetries must be at least 1, got {$maxRetries}.");
        }

        if ($retryDelayMs < 0) {
            throw new InvalidArgumentException("retryDelayMs must not be negative, got {$retryDelayMs}.");
        }

        if ($slowQueryThreshold < 0) {
            throw new InvalidArgumentException("slowQueryThreshold must not be negative, got {$slowQueryThreshold}.");
        }
    }

    /**
     * Build the shared options from a raw connection parameter block.
     *
     * @param string $connection The connection name (used in validation messages)
     * @param array<string, mixed> $block
     *
     * @throws InvalidArgumentException When a value is malformed or out of range
     *
     * @return self
     */
    public static function fromArray(string $connection, array $block): self
    {
        return new self(
            prefix: ConfigValue::string($block, 'prefix', ''),
            read: ConfigValue::replicas($connection, $block),
            sticky: ConfigValue::bool($block, 'sticky', true),
            maxRetries: ConfigValue::int($block, 'maxRetries', 3),
            retryDelayMs: ConfigValue::int($block, 'retryDelayMs', 200),
            slowQueryThreshold: ConfigValue::int($block, 'slowQueryThreshold', 100),
        );
    }
}

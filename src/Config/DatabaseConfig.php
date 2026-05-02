<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Config;

use PHPdot\Container\Attribute\Config;

#[Config('database')]
final readonly class DatabaseConfig
{
    /**
     * @param string $driver Database driver (mysql, pgsql, sqlite)
     * @param string $host Database host
     * @param int $port Database port
     * @param string $database Database name or file path for SQLite
     * @param string $username Database username
     * @param string $password Database password
     * @param string $charset Character set
     * @param string $prefix Table name prefix
     * @param list<array<string, mixed>> $read Read replica configurations
     * @param bool $sticky Stick to write connection after writing
     * @param int $maxRetries Reconnection attempts
     * @param int $retryDelayMs Base delay between retries in milliseconds
     * @param int $slowQueryThreshold Slow query warning threshold in milliseconds
     * @param array<string, mixed> $options Driver-specific PDO options
     */
    public function __construct(
        public string $driver = 'mysql',
        public string $host = 'localhost',
        public int $port = 3306,
        public string $database = '',
        public string $username = 'root',
        public string $password = '',
        public string $charset = 'utf8mb4',
        public string $prefix = '',
        public array $read = [],
        public bool $sticky = true,
        public int $maxRetries = 3,
        public int $retryDelayMs = 200,
        public int $slowQueryThreshold = 100,
        public array $options = [],
    ) {}
}

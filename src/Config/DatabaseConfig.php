<?php

declare(strict_types=1);

/**
 * The database configuration set: a map of named connections plus the default.
 *
 * Each entry in $connections is a driver-tagged parameter block (an array whose
 * 'driver' key selects the engine), letting heterogeneous engines and several
 * instances of the same engine coexist. Blocks are resolved into typed
 * ConnectionConfig objects by ConnectionFactory.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Config;

use PHPdot\Container\Attribute\Config;

#[Config('database')]
final readonly class DatabaseConfig
{
    /**
     * Hold the raw multi-connection database configuration block.
     *
     * @param string $default The name of the connection used when none is specified
     * @param array<string, array<string, mixed>> $connections Named driver-tagged parameter blocks
     */
    public function __construct(
        public string $default = 'default',
        public array $connections = [
            'default' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => '',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
            ],
        ],
    ) {}

    /**
     * Determine whether a connection with the given name is defined.
     *
     * @param string $name The connection name
     *
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * Get all defined connection names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->connections);
    }
}

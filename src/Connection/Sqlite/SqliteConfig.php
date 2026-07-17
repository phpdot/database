<?php

declare(strict_types=1);

/**
 * SQLite Config
 *
 * SQLite connection configuration, needing only a database path or ':memory:'.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Connection\Sqlite;

use Doctrine\DBAL\DriverManager;
use PHPdot\Database\Connection\ConfigValue;
use PHPdot\Database\Connection\ConnectionConfig;
use PHPdot\Database\Connection\ConnectionOptions;

/**
 * @phpstan-import-type Params from DriverManager
 */
final readonly class SqliteConfig implements ConnectionConfig
{
    /**
     * Build a SQLite connection configuration.
     *
     * @param string $database Filesystem path to the database, or ':memory:'
     * @param array<string, mixed> $driverOptions Driver-specific \PDO options
     * @param ConnectionOptions $options Driver-agnostic behaviour
     */
    public function __construct(
        public string $database,
        public array $driverOptions = [],
        private ConnectionOptions $options = new ConnectionOptions(),
    ) {}

    /**
     * Build a validated SQLite configuration from a raw parameter block.
     *
     * @param array<string, mixed> $block
     * @param string $name
     *
     * @return self
     */
    public static function fromArray(string $name, array $block): self
    {
        return new self(
            database: ConfigValue::requireString($name, 'sqlite', $block, 'database'),
            driverOptions: ConfigValue::driverOptions($block),
            options: ConnectionOptions::fromArray($name, $block),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function driver(): string
    {
        return 'sqlite';
    }

    /**
     * {@inheritDoc}
     */
    public function database(): string
    {
        return $this->database;
    }

    /**
     * @return Params
     */
    public function dbalParams(): array
    {
        return [
            'driver' => 'pdo_sqlite',
            'path' => $this->database,
            'driverOptions' => $this->driverOptions,
        ];
    }

    /**
     * SQLite has no network read replicas.
     *
     * @return Params|null
     */
    public function readReplicaParams(): ?array
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function options(): ConnectionOptions
    {
        return $this->options;
    }
}

<?php

declare(strict_types=1);

/**
 * MySQL Config
 *
 * MySQL connection configuration: host, port, database, credentials and charset.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Connection\MySql;

use Doctrine\DBAL\DriverManager;
use PHPdot\Database\Connection\ConfigValue;
use PHPdot\Database\Connection\ConnectionConfig;
use PHPdot\Database\Connection\ConnectionOptions;

/**
 * @phpstan-import-type Params from DriverManager
 */
final readonly class MySqlConfig implements ConnectionConfig
{
    /**
     * Build a MySQL connection configuration.
     *
     * @param string $host Database host
     * @param int $port Database port
     * @param string $database Database name
     * @param string $username Database username
     * @param string $password Database password
     * @param string $charset Character set
     * @param array<string, mixed> $driverOptions Driver-specific \PDO options
     * @param ConnectionOptions $options Driver-agnostic behaviour
     */
    public function __construct(
        public string $database,
        public string $host = '127.0.0.1',
        public int $port = 3306,
        public string $username = 'root',
        public string $password = '',
        public string $charset = 'utf8mb4',
        public array $driverOptions = [],
        private ConnectionOptions $options = new ConnectionOptions(),
    ) {}

    /**
     * Build a validated MySQL configuration from a raw parameter block.
     *
     * @param array<string, mixed> $block
     * @param string $name
     *
     * @return self
     */
    public static function fromArray(string $name, array $block): self
    {
        return new self(
            database: ConfigValue::requireString($name, 'mysql', $block, 'database'),
            host: ConfigValue::string($block, 'host', '127.0.0.1'),
            port: ConfigValue::int($block, 'port', 3306),
            username: ConfigValue::string($block, 'username', 'root'),
            password: ConfigValue::string($block, 'password', ''),
            charset: ConfigValue::string($block, 'charset', 'utf8mb4'),
            driverOptions: ConfigValue::driverOptions($block),
            options: ConnectionOptions::fromArray($name, $block),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function driver(): string
    {
        return 'mysql';
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
        return $this->buildParams();
    }

    /**
     * @return Params|null
     */
    public function readReplicaParams(): ?array
    {
        if ($this->options->read === []) {
            return null;
        }

        return $this->buildParams($this->options->read[array_rand($this->options->read)]);
    }

    /**
     * {@inheritDoc}
     */
    public function options(): ConnectionOptions
    {
        return $this->options;
    }

    /**
     * Assemble the Doctrine DBAL parameter array for MySQL.
     *
     * @param array<string, mixed> $override Replica overrides applied over the primary values
     *
     * @return Params
     */
    private function buildParams(array $override = []): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => ConfigValue::string($override, 'host', $this->host),
            'port' => ConfigValue::int($override, 'port', $this->port),
            'dbname' => ConfigValue::string($override, 'database', $this->database),
            'user' => ConfigValue::string($override, 'username', $this->username),
            'password' => ConfigValue::string($override, 'password', $this->password),
            'charset' => ConfigValue::string($override, 'charset', $this->charset),
            'driverOptions' => $this->driverOptions,
        ];
    }
}

<?php

declare(strict_types=1);

/**
 * PostgreSQL connection configuration: host, port, database, credentials and
 * charset. Its charset default is 'utf8' — PostgreSQL rejects MySQL's 'utf8mb4'
 * as a client_encoding, so that value is normalised away.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Connection\Postgres;

use Doctrine\DBAL\DriverManager;
use PHPdot\Database\Connection\ConfigValue;
use PHPdot\Database\Connection\ConnectionConfig;
use PHPdot\Database\Connection\ConnectionOptions;

/**
 * @phpstan-import-type Params from DriverManager
 */
final readonly class PostgresConfig implements ConnectionConfig
{
    /**
     * Build a PostgreSQL connection configuration.
     *
     * @param string $host Database host
     * @param int $port Database port
     * @param string $database Database name
     * @param string $username Database username
     * @param string $password Database password
     * @param string $charset Client encoding
     * @param string $gssencmode libpq GSSAPI encryption mode ('' = disable on macOS, libpq
     *                           default elsewhere). libpq's default 'prefer' probes the
     *                           Kerberos credential cache via CFPreferences/XPC, and inside a
     *                           forked worker macOS kills the process with EXC_GUARD — set
     *                           'prefer'/'require' explicitly only if you actually use Kerberos.
     * @param array<string, mixed> $driverOptions Driver-specific \PDO options
     * @param ConnectionOptions $options Driver-agnostic behaviour
     */
    public function __construct(
        public string $database,
        public string $host = '127.0.0.1',
        public int $port = 5432,
        public string $username = 'postgres',
        public string $password = '',
        public string $charset = 'utf8',
        public string $gssencmode = '',
        public array $driverOptions = [],
        private ConnectionOptions $options = new ConnectionOptions(),
    ) {}

    /**
     * Build a validated PostgreSQL configuration from a raw parameter block.
     *
     * @param array<string, mixed> $block
     * @param string $name
     *
     * @return self
     */
    public static function fromArray(string $name, array $block): self
    {
        return new self(
            database: ConfigValue::requireString($name, 'pgsql', $block, 'database'),
            host: ConfigValue::string($block, 'host', '127.0.0.1'),
            port: ConfigValue::int($block, 'port', 5432),
            username: ConfigValue::string($block, 'username', 'postgres'),
            password: ConfigValue::string($block, 'password', ''),
            charset: ConfigValue::string($block, 'charset', 'utf8'),
            gssencmode: ConfigValue::string($block, 'gssencmode', ''),
            driverOptions: ConfigValue::driverOptions($block),
            options: ConnectionOptions::fromArray($name, $block),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function driver(): string
    {
        return 'pgsql';
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
     * Assemble the Doctrine DBAL parameter array for PostgreSQL.
     *
     * @param array<string, mixed> $override Replica overrides applied over the primary values
     *
     * @return Params
     */
    private function buildParams(array $override = []): array
    {
        $charset = ConfigValue::string($override, 'charset', $this->charset);

        $gssencmode = ConfigValue::string($override, 'gssencmode', $this->gssencmode);
        if ($gssencmode === '' && PHP_OS_FAMILY === 'Darwin') {
            $gssencmode = 'disable';
        }

        $params = [
            'driver' => 'pdo_pgsql',
            'host' => ConfigValue::string($override, 'host', $this->host),
            'port' => ConfigValue::int($override, 'port', $this->port),
            'dbname' => ConfigValue::string($override, 'database', $this->database),
            'user' => ConfigValue::string($override, 'username', $this->username),
            'password' => ConfigValue::string($override, 'password', $this->password),
            'charset' => $charset === 'utf8mb4' ? 'utf8' : $charset,
            'driverOptions' => $this->driverOptions,
        ];

        if ($gssencmode !== '') {
            $params['gssencmode'] = $gssencmode;
        }

        return $params;
    }
}

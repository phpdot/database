<?php

declare(strict_types=1);

/**
 * A resolved, driver-specific connection configuration.
 *
 * Each database engine connects differently, so there is one implementation per
 * driver (MySqlConfig, PostgresConfig, SqliteConfig) carrying only the keys that
 * driver actually uses. Each knows how to produce its own Doctrine DBAL
 * parameters; shared behaviour lives in ConnectionOptions.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Connection;

use Doctrine\DBAL\DriverManager;

/**
 * @phpstan-import-type Params from DriverManager
 */
interface ConnectionConfig
{
    /**
     * The driver name (mysql, pgsql, sqlite).
     *
     * @return string
     */
    public function driver(): string;

    /**
     * The database name, or file path for SQLite.
     *
     * @return string
     */
    public function database(): string;

    /**
     * Doctrine DBAL parameters for the primary (write) connection.
     *
     * @return Params
     */
    public function dbalParams(): array;

    /**
     * Doctrine DBAL parameters for a randomly chosen read replica, or null when
     * the driver has no read replicas configured.
     *
     * @return Params|null
     */
    public function readReplicaParams(): ?array;

    /**
     * The driver-agnostic behaviour (prefix, replicas, sticky, retries).
     *
     * @return ConnectionOptions
     */
    public function options(): ConnectionOptions;
}

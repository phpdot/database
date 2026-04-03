<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query\Stub;

use PHPdot\Database\Connection;
use PHPdot\Database\Query\Builder;
use PHPdot\Database\Query\Grammar\Grammar;
use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPdot\Database\Query\Grammar\PostgresGrammar;
use PHPdot\Database\Query\Grammar\SqliteGrammar;

/**
 * Helper to create Builder instances for unit tests without a real database connection.
 *
 * Since Connection is final and cannot be mocked, we use reflection to construct
 * a Builder with a Connection that has no actual database backing.
 */
final class ConnectionStub
{
    /**
     * Create a Builder with a MySQL grammar, bypassing Connection's database requirement.
     */
    public static function mysqlBuilder(string $table = 'users'): Builder
    {
        return self::builderFor(new MySqlGrammar(), $table);
    }

    /**
     * Create a Builder with a PostgreSQL grammar.
     */
    public static function postgresBuilder(string $table = 'users'): Builder
    {
        return self::builderFor(new PostgresGrammar(), $table);
    }

    /**
     * Create a Builder with a SQLite grammar.
     */
    public static function sqliteBuilder(string $table = 'users'): Builder
    {
        return self::builderFor(new SqliteGrammar(), $table);
    }

    private static function builderFor(Grammar $grammar, string $table): Builder
    {
        $connection = self::createConnectionWithoutConnecting();
        $builder = new Builder($connection, $grammar);

        return $builder->from($table);
    }

    /**
     * Create a Connection instance without actually connecting to a database.
     * Uses reflection to instantiate with a minimal SQLite in-memory config.
     */
    private static function createConnectionWithoutConnecting(): Connection
    {
        $config = new \PHPdot\Database\Config\DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        );

        return new Connection($config);
    }
}

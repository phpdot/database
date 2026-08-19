<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\PostgreSql;

use PHPdot\Database\Connection\Postgres\PostgresConfig;
use PHPdot\Database\DatabaseConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('pgsql')]
abstract class PostgreSqlTestCase extends TestCase
{
    protected DatabaseConnection $db;

    protected function setUp(): void
    {
        $this->db = new DatabaseConnection(new PostgresConfig(
            host: getenv('PG_HOST') ?: '127.0.0.1',
            port: (int) (getenv('PG_PORT') ?: 5432),
            database: getenv('PG_DB') ?: 'phpdot_test',
            username: getenv('PG_USER') ?: 'postgres',
            password: getenv('PG_PASS') ?: 'postgres',
        ));

        try {
            $this->db->select('SELECT 1');
        } catch (\Throwable $e) {
            self::skipOrFail('PostgreSQL is not available: ' . $e->getMessage());
        }

        $this->db->statement("SET TIME ZONE 'UTC'");
        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->db->isConnected()) {
            $this->cleanDatabase();
        }

        $this->db->close();
    }

    protected function cleanDatabase(): void
    {
        foreach (['posts', 'users'] as $table) {
            $this->db->unprepared("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    protected function createUsersTable(): void
    {
        $this->db->unprepared('
            CREATE TABLE users (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                age INTEGER,
                active SMALLINT DEFAULT 1,
                balance NUMERIC(10,2) DEFAULT 0,
                tags JSONB,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    protected function seedUsers(): void
    {
        $this->db->table('users')->insertBatch([
            ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30, 'active' => 1, 'balance' => 100.50, 'tags' => '["admin"]'],
            ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25, 'active' => 1, 'balance' => 200.00, 'tags' => '["user"]'],
            ['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 35, 'active' => 0, 'balance' => 50.00, 'tags' => '["user"]'],
            ['name' => 'Diana', 'email' => 'diana@example.com', 'age' => 28, 'active' => 1, 'balance' => 0.00, 'tags' => '["user"]'],
            ['name' => 'Eve', 'email' => 'eve@example.com', 'age' => 22, 'active' => 1, 'balance' => 500.00, 'tags' => '["vip"]'],
        ]);
    }

    /**
     * Skip when the database is absent — unless DB_TESTS_REQUIRED=1, when the
     * absence is a FAILURE.
     *
     * A silent skip is how a suite reports green while the tests that matter
     * never ran: this package's integration coverage sat skipped, unnoticed,
     * because nothing distinguished "passed" from "not attempted". Local runs
     * without docker stay convenient; CI sets the flag and cannot lie.
     *
     * @param string $reason Why the database could not be reached
     *
     * @return never
     */
    protected function skipOrFail(string $reason): never
    {
        if (getenv('DB_TESTS_REQUIRED') === '1') {
            self::fail($reason . ' (DB_TESTS_REQUIRED=1 — integration coverage may not be skipped)');
        }

        self::markTestSkipped($reason);
    }
}

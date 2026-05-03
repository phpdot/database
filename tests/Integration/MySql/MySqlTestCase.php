<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\DatabaseConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('mysql')]
abstract class MySqlTestCase extends TestCase
{
    protected DatabaseConnection $db;

    protected function setUp(): void
    {
        $this->db = new DatabaseConnection(new DatabaseConfig(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            database: 'phpdot_test',
            username: 'root',
            password: 'root',
        ));

        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        $this->db->close();
    }

    protected function cleanDatabase(): void
    {
        $this->db->unprepared('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $this->db->select("SHOW TABLES");
        foreach ($tables->all() as $row) {
            $values = array_values($row);
            $tableName = (string) ($values[0] ?? '');
            if ($tableName !== '') {
                $this->db->unprepared("DROP TABLE IF EXISTS `{$tableName}`");
            }
        }
        $this->db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function createUsersTable(): void
    {
        $this->db->unprepared('
            CREATE TABLE users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                age INT DEFAULT NULL,
                active TINYINT(1) DEFAULT 1,
                balance DECIMAL(10,2) DEFAULT 0.00,
                settings JSON DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    protected function createPostsTable(): void
    {
        $this->db->unprepared('
            CREATE TABLE posts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT,
                published TINYINT(1) DEFAULT 0,
                views INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    protected function seedUsers(): void
    {
        $this->db->table('users')->insertBatch([
            ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30, 'active' => 1, 'balance' => 100.50],
            ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25, 'active' => 1, 'balance' => 200.00],
            ['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 35, 'active' => 0, 'balance' => 50.00],
            ['name' => 'Diana', 'email' => 'diana@example.com', 'age' => 28, 'active' => 1, 'balance' => 0.00],
            ['name' => 'Eve', 'email' => 'eve@example.com', 'age' => 22, 'active' => 1, 'balance' => 500.00],
        ]);
    }

    protected function seedPosts(): void
    {
        $this->db->table('posts')->insertBatch([
            ['user_id' => 1, 'title' => 'First Post', 'body' => 'Hello world', 'published' => 1, 'views' => 100],
            ['user_id' => 1, 'title' => 'Second Post', 'body' => 'More content', 'published' => 1, 'views' => 50],
            ['user_id' => 2, 'title' => 'Bob Post', 'body' => 'Bob writes', 'published' => 0, 'views' => 10],
            ['user_id' => 3, 'title' => 'Draft', 'body' => 'Work in progress', 'published' => 0, 'views' => 0],
        ]);
    }
}

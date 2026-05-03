<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\Sqlite;

use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

abstract class SqliteTestCase extends TestCase
{
    protected DatabaseConnection $db;

    protected function setUp(): void
    {
        $this->db = new DatabaseConnection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));
        $this->createTables();
    }

    protected function createTables(): void
    {
        $this->db->unprepared('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, age INTEGER, active INTEGER DEFAULT 1, balance REAL DEFAULT 0.0, created_at TEXT, updated_at TEXT)');
        $this->db->unprepared('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL, body TEXT, published INTEGER DEFAULT 0, views INTEGER DEFAULT 0, created_at TEXT, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)');
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
            ['user_id' => 1, 'title' => 'First Post', 'body' => 'Hello', 'published' => 1, 'views' => 100],
            ['user_id' => 1, 'title' => 'Second Post', 'body' => 'More', 'published' => 1, 'views' => 50],
            ['user_id' => 2, 'title' => 'Bob Post', 'body' => 'Bob writes', 'published' => 0, 'views' => 10],
        ]);
    }
}

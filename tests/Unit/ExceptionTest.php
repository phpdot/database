<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit;

use PHPdot\Database\Exception\ConnectionException;
use PHPdot\Database\Exception\DatabaseException;
use PHPdot\Database\Exception\MigrationException;
use PHPdot\Database\Exception\QueryException;
use PHPdot\Database\Exception\RecordNotFoundException;
use PHPdot\Database\Exception\SchemaException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionTest extends TestCase
{
    // ---------------------------------------------------------------
    //  DatabaseException
    // ---------------------------------------------------------------

    #[Test]
    public function databaseExceptionExtendsRuntimeException(): void
    {
        $exception = new DatabaseException('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    // ---------------------------------------------------------------
    //  ConnectionException
    // ---------------------------------------------------------------

    #[Test]
    public function connectionExceptionExtendsDatabaseException(): void
    {
        $exception = ConnectionException::connectionFailed('mysql', 'localhost', 'refused');

        self::assertInstanceOf(DatabaseException::class, $exception);
    }

    #[Test]
    public function connectionFailedContainsDriverHostAndError(): void
    {
        $exception = ConnectionException::connectionFailed('mysql', '10.0.0.1', 'Connection refused');

        self::assertStringContainsString('mysql', $exception->getMessage());
        self::assertStringContainsString('10.0.0.1', $exception->getMessage());
        self::assertStringContainsString('Connection refused', $exception->getMessage());
    }

    #[Test]
    public function reconnectFailedContainsError(): void
    {
        $exception = ConnectionException::reconnectFailed('timeout');

        self::assertStringContainsString('timeout', $exception->getMessage());
        self::assertStringContainsString('reconnect', strtolower($exception->getMessage()));
    }

    #[Test]
    public function disconnectedContainsError(): void
    {
        $exception = ConnectionException::disconnected('gone away');

        self::assertStringContainsString('gone away', $exception->getMessage());
    }

    // ---------------------------------------------------------------
    //  QueryException
    // ---------------------------------------------------------------

    #[Test]
    public function queryExceptionExtendsDatabaseException(): void
    {
        $exception = QueryException::executionFailed('SELECT 1', [], 'error');

        self::assertInstanceOf(DatabaseException::class, $exception);
    }

    #[Test]
    public function executionFailedContainsSqlAndError(): void
    {
        $exception = QueryException::executionFailed(
            'SELECT * FROM users WHERE id = ?',
            [42],
            'table not found',
        );

        self::assertStringContainsString('SELECT * FROM users WHERE id = ?', $exception->getMessage());
        self::assertStringContainsString('table not found', $exception->getMessage());
    }

    #[Test]
    public function queryExceptionCarriesSqlAndBindings(): void
    {
        $exception = QueryException::executionFailed(
            'INSERT INTO users (name) VALUES (?)',
            ['Alice'],
            'duplicate key',
        );

        self::assertSame('INSERT INTO users (name) VALUES (?)', $exception->getSql());
        self::assertSame(['Alice'], $exception->getBindings());
    }

    #[Test]
    public function deadlockContainsSql(): void
    {
        $exception = QueryException::deadlock('UPDATE accounts SET balance = 0');

        self::assertStringContainsString('UPDATE accounts SET balance = 0', $exception->getMessage());
        self::assertStringContainsString('eadlock', $exception->getMessage());
        self::assertSame('UPDATE accounts SET balance = 0', $exception->getSql());
        self::assertSame([], $exception->getBindings());
    }

    #[Test]
    public function timeoutContainsSql(): void
    {
        $exception = QueryException::timeout('SELECT SLEEP(100)');

        self::assertStringContainsString('SELECT SLEEP(100)', $exception->getMessage());
        self::assertStringContainsString('timed out', $exception->getMessage());
        self::assertSame('SELECT SLEEP(100)', $exception->getSql());
    }

    // ---------------------------------------------------------------
    //  RecordNotFoundException
    // ---------------------------------------------------------------

    #[Test]
    public function recordNotFoundExceptionExtendsDatabaseException(): void
    {
        $exception = RecordNotFoundException::recordNotFound('users');

        self::assertInstanceOf(DatabaseException::class, $exception);
    }

    #[Test]
    public function recordNotFoundContainsTableName(): void
    {
        $exception = RecordNotFoundException::recordNotFound('orders');

        self::assertStringContainsString('orders', $exception->getMessage());
    }

    // ---------------------------------------------------------------
    //  SchemaException
    // ---------------------------------------------------------------

    #[Test]
    public function schemaExceptionExtendsDatabaseException(): void
    {
        $exception = SchemaException::tableNotFound('users');

        self::assertInstanceOf(DatabaseException::class, $exception);
    }

    #[Test]
    public function tableNotFoundContainsTableName(): void
    {
        $exception = SchemaException::tableNotFound('products');

        self::assertStringContainsString('products', $exception->getMessage());
    }

    #[Test]
    public function tableAlreadyExistsContainsTableName(): void
    {
        $exception = SchemaException::tableAlreadyExists('users');

        self::assertStringContainsString('users', $exception->getMessage());
        self::assertStringContainsString('already exists', $exception->getMessage());
    }

    #[Test]
    public function columnNotFoundContainsTableAndColumnName(): void
    {
        $exception = SchemaException::columnNotFound('users', 'age');

        self::assertStringContainsString('users', $exception->getMessage());
        self::assertStringContainsString('age', $exception->getMessage());
    }

    #[Test]
    public function unsupportedOperationContainsOperationAndDriver(): void
    {
        $exception = SchemaException::unsupportedOperation('RENAME COLUMN', 'sqlite');

        self::assertStringContainsString('RENAME COLUMN', $exception->getMessage());
        self::assertStringContainsString('sqlite', $exception->getMessage());
    }

    // ---------------------------------------------------------------
    //  MigrationException
    // ---------------------------------------------------------------

    #[Test]
    public function migrationExceptionExtendsDatabaseException(): void
    {
        $exception = MigrationException::migrationFailed('2024_01_create_users', 'syntax error');

        self::assertInstanceOf(DatabaseException::class, $exception);
    }

    #[Test]
    public function migrationFailedContainsMigrationAndError(): void
    {
        $exception = MigrationException::migrationFailed('2024_01_create_users', 'syntax error');

        self::assertStringContainsString('2024_01_create_users', $exception->getMessage());
        self::assertStringContainsString('syntax error', $exception->getMessage());
    }

    #[Test]
    public function tableNotCreatedReturnsDescriptiveMessage(): void
    {
        $exception = MigrationException::tableNotCreated();

        self::assertStringContainsString('migrations table', $exception->getMessage());
    }

    #[Test]
    public function rollbackFailedContainsMigrationAndError(): void
    {
        $exception = MigrationException::rollbackFailed('2024_02_add_roles', 'foreign key constraint');

        self::assertStringContainsString('2024_02_add_roles', $exception->getMessage());
        self::assertStringContainsString('foreign key constraint', $exception->getMessage());
    }
}

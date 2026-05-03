<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Migration;

use PHPdot\Database\Config\DatabaseConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\Migration\MigrationRepository;
use PHPdot\Database\Migration\Migrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private DatabaseConnection $connection;

    private MigrationRepository $repository;

    private Migrator $migrator;

    private string $migrationsPath;

    protected function setUp(): void
    {
        $this->connection = new DatabaseConnection(new DatabaseConfig(
            driver: 'sqlite',
            database: ':memory:',
        ));

        $this->migrationsPath = dirname(__DIR__, 2) . '/Fixtures/migrations';
        $this->repository = new MigrationRepository($this->connection);
        $this->migrator = new Migrator($this->connection, $this->repository);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    // ---------------------------------------------------------------
    //  pretend
    // ---------------------------------------------------------------

    #[Test]
    public function pretendReturnsPendingMigrationsWithQueries(): void
    {
        $result = $this->migrator->pretend($this->migrationsPath);

        self::assertCount(2, $result);
        self::assertSame('2026_04_03_000001_create_test_table', $result[0]['migration']);
        self::assertSame('2026_04_03_000002_create_test_tags_table', $result[1]['migration']);
        self::assertNotEmpty($result[0]['queries']);
        self::assertNotEmpty($result[1]['queries']);
    }

    #[Test]
    public function pretendDoesNotPersistChanges(): void
    {
        $this->migrator->pretend($this->migrationsPath);

        $schema = $this->connection->schema();

        self::assertFalse($schema->hasTable('test_migration_table'));
        self::assertFalse($schema->hasTable('test_tags_table'));
    }

    #[Test]
    public function pretendReturnsEmptyWhenNoPendingMigrations(): void
    {
        $this->migrator->run($this->migrationsPath);

        $result = $this->migrator->pretend($this->migrationsPath);

        self::assertSame([], $result);
    }

    #[Test]
    public function pretendCapturesSqlStrings(): void
    {
        $result = $this->migrator->pretend($this->migrationsPath);

        foreach ($result as $entry) {
            foreach ($entry['queries'] as $query) {
                self::assertIsString($query);
            }
        }
    }

    // ---------------------------------------------------------------
    //  refresh
    // ---------------------------------------------------------------

    #[Test]
    public function refreshResetsAndRerunsAllMigrations(): void
    {
        $this->migrator->run($this->migrationsPath);
        self::assertTrue($this->connection->schema()->hasTable('test_migration_table'));

        $executed = $this->migrator->refresh($this->migrationsPath);

        self::assertCount(2, $executed);
        self::assertTrue($this->connection->schema()->hasTable('test_migration_table'));
        self::assertTrue($this->connection->schema()->hasTable('test_tags_table'));
    }

    #[Test]
    public function refreshOnFreshDatabaseRunsAllMigrations(): void
    {
        $executed = $this->migrator->refresh($this->migrationsPath);

        self::assertCount(2, $executed);
        self::assertTrue($this->connection->schema()->hasTable('test_migration_table'));
    }

    #[Test]
    public function refreshResetsBatchNumbers(): void
    {
        $this->migrator->run($this->migrationsPath);
        $this->migrator->refresh($this->migrationsPath);

        $batch = $this->repository->getBatch('2026_04_03_000001_create_test_table');

        self::assertSame(1, $batch);
    }

    // ---------------------------------------------------------------
    //  status
    // ---------------------------------------------------------------

    #[Test]
    public function statusShowsAllMigrationsAsPendingInitially(): void
    {
        $status = $this->migrator->status($this->migrationsPath);

        self::assertCount(2, $status);

        foreach ($status as $entry) {
            self::assertSame('pending', $entry['status']);
            self::assertNull($entry['batch']);
        }
    }

    #[Test]
    public function statusShowsMigrationsAsRanAfterExecution(): void
    {
        $this->migrator->run($this->migrationsPath);

        $status = $this->migrator->status($this->migrationsPath);

        self::assertCount(2, $status);

        foreach ($status as $entry) {
            self::assertSame('ran', $entry['status']);
            self::assertSame(1, $entry['batch']);
        }
    }

    #[Test]
    public function statusContainsMigrationNameAndBatch(): void
    {
        $this->migrator->run($this->migrationsPath);

        $status = $this->migrator->status($this->migrationsPath);

        self::assertSame('2026_04_03_000001_create_test_table', $status[0]['migration']);
        self::assertSame('2026_04_03_000002_create_test_tags_table', $status[1]['migration']);
        self::assertSame(1, $status[0]['batch']);
        self::assertSame(1, $status[1]['batch']);
    }

    #[Test]
    public function statusReturnsEmptyForEmptyDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phpdot_empty_migrations_' . uniqid();
        mkdir($tmpDir);

        try {
            $status = $this->migrator->status($tmpDir);
            self::assertSame([], $status);
        } finally {
            rmdir($tmpDir);
        }
    }

    // ---------------------------------------------------------------
    //  getBatch on repository
    // ---------------------------------------------------------------

    #[Test]
    public function getBatchReturnsNullForUnknownMigration(): void
    {
        $this->repository->createRepository();

        $batch = $this->repository->getBatch('nonexistent_migration');

        self::assertNull($batch);
    }

    #[Test]
    public function getBatchReturnsBatchNumberForRanMigration(): void
    {
        $this->migrator->run($this->migrationsPath);

        $batch = $this->repository->getBatch('2026_04_03_000001_create_test_table');

        self::assertSame(1, $batch);
    }
}

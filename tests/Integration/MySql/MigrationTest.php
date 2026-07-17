<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Migration\MigrationRepository;
use PHPdot\Database\Migration\Migrator;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
final class MigrationTest extends MySqlTestCase
{
    private Migrator $migrator;

    private MigrationRepository $repository;

    private string $migrationsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationsPath = dirname(__DIR__, 2) . '/Fixtures/migrations';
        $this->repository = new MigrationRepository($this->db);
        $this->migrator = new Migrator($this->db, $this->repository);
    }

    public function testRunCreatesTable(): void
    {
        $executed = $this->migrator->run($this->migrationsPath);

        self::assertNotEmpty($executed);
        self::assertTrue($this->db->schema()->hasTable('test_migration_table'));
        self::assertTrue($this->db->schema()->hasTable('test_tags_table'));
    }

    public function testStatusShowsMigrationAsRan(): void
    {
        $this->migrator->run($this->migrationsPath);

        $ran = $this->repository->getRan();

        self::assertContains('2026_04_03_000001_create_test_table', $ran);
        self::assertContains('2026_04_03_000002_create_test_tags_table', $ran);
    }

    public function testPendingShowsNoPendingAfterRun(): void
    {
        $this->migrator->run($this->migrationsPath);

        $pending = $this->migrator->getPending($this->migrationsPath);

        self::assertSame([], $pending);
    }

    public function testRollbackDropsTable(): void
    {
        $this->migrator->run($this->migrationsPath);
        self::assertTrue($this->db->schema()->hasTable('test_migration_table'));

        $rolledBack = $this->migrator->rollback($this->migrationsPath);

        self::assertNotEmpty($rolledBack);
        self::assertFalse($this->db->schema()->hasTable('test_migration_table'));
        self::assertFalse($this->db->schema()->hasTable('test_tags_table'));
    }

    public function testResetRollsBackAll(): void
    {
        $this->migrator->run($this->migrationsPath);

        $rolledBack = $this->migrator->reset($this->migrationsPath);

        self::assertNotEmpty($rolledBack);
        self::assertFalse($this->db->schema()->hasTable('test_migration_table'));
        self::assertFalse($this->db->schema()->hasTable('test_tags_table'));
    }
}

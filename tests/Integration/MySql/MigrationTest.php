<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Migration\MigrationRepository;
use PHPdot\Database\Migration\Migrator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('mysql')]
#[Group('integration')]
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

    #[Test]
    public function runCreatesTable(): void
    {
        $executed = $this->migrator->run($this->migrationsPath);

        self::assertNotEmpty($executed);
        self::assertTrue($this->db->schema()->hasTable('test_migration_table'));
        self::assertTrue($this->db->schema()->hasTable('test_tags_table'));
    }

    #[Test]
    public function statusShowsMigrationAsRan(): void
    {
        $this->migrator->run($this->migrationsPath);

        $ran = $this->repository->getRan();

        self::assertContains('2026_04_03_000001_create_test_table', $ran);
        self::assertContains('2026_04_03_000002_create_test_tags_table', $ran);
    }

    #[Test]
    public function pendingShowsNoPendingAfterRun(): void
    {
        $this->migrator->run($this->migrationsPath);

        $pending = $this->migrator->getPending($this->migrationsPath);

        self::assertSame([], $pending);
    }

    #[Test]
    public function rollbackDropsTable(): void
    {
        $this->migrator->run($this->migrationsPath);
        self::assertTrue($this->db->schema()->hasTable('test_migration_table'));

        $rolledBack = $this->migrator->rollback($this->migrationsPath);

        self::assertNotEmpty($rolledBack);
        self::assertFalse($this->db->schema()->hasTable('test_migration_table'));
        self::assertFalse($this->db->schema()->hasTable('test_tags_table'));
    }

    #[Test]
    public function resetRollsBackAll(): void
    {
        $this->migrator->run($this->migrationsPath);

        $rolledBack = $this->migrator->reset($this->migrationsPath);

        self::assertNotEmpty($rolledBack);
        self::assertFalse($this->db->schema()->hasTable('test_migration_table'));
        self::assertFalse($this->db->schema()->hasTable('test_tags_table'));
    }
}

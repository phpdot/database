<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\Sqlite;

use PHPdot\Database\Schema\Blueprint;

final class SchemaBuilderTest extends SqliteTestCase
{
    public function testCreateTableWithVariousTypes(): void
    {
        $schema = $this->db->schema();

        $schema->create('test_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('bio');
            $table->integer('age');
            $table->bigInteger('big_num');
            $table->float('score');
            $table->decimal('price', 10, 2);
            $table->boolean('active');
            $table->date('birth_date');
            $table->dateTime('event_at');
            $table->timestamp('logged_at')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('ref_uuid')->nullable();
        });

        self::assertTrue($schema->hasTable('test_types'));
    }

    public function testHasTableReturnsTrue(): void
    {
        self::assertTrue($this->db->schema()->hasTable('users'));
    }

    public function testHasTableReturnsFalse(): void
    {
        self::assertFalse($this->db->schema()->hasTable('nonexistent_table'));
    }

    public function testHasColumnReturnsTrue(): void
    {
        $schema = $this->db->schema();

        self::assertTrue($schema->hasColumn('users', 'name'));
        self::assertTrue($schema->hasColumn('users', 'email'));
    }

    public function testHasColumnReturnsFalse(): void
    {
        self::assertFalse($this->db->schema()->hasColumn('users', 'nonexistent'));
    }

    public function testGetColumnListing(): void
    {
        $columns = $this->db->schema()->getColumnListing('users');

        self::assertContains('id', $columns);
        self::assertContains('name', $columns);
        self::assertContains('email', $columns);
        self::assertContains('age', $columns);
    }

    public function testDropTable(): void
    {
        $schema = $this->db->schema();

        self::assertTrue($schema->hasTable('users'));

        $schema->drop('users');

        self::assertFalse($schema->hasTable('users'));
    }

    public function testDropIfExistsNonExistingDoesNotError(): void
    {
        $schema = $this->db->schema();

        $schema->dropIfExists('totally_nonexistent_table');

        self::assertFalse($schema->hasTable('totally_nonexistent_table'));
    }

    public function testRenameTable(): void
    {
        $schema = $this->db->schema();

        $schema->rename('users', 'members');

        self::assertFalse($schema->hasTable('users'));
        self::assertTrue($schema->hasTable('members'));
    }

    public function testAlterTableAddColumn(): void
    {
        $schema = $this->db->schema();

        $schema->table('users', function (Blueprint $table): void {
            $table->string('nickname', 100)->nullable();
        });

        self::assertTrue($schema->hasColumn('users', 'nickname'));
    }

    public function testTimestampsCreatesBothColumns(): void
    {
        $schema = $this->db->schema();

        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        $columns = $schema->getColumnListing('articles');
        self::assertContains('created_at', $columns);
        self::assertContains('updated_at', $columns);
    }

    public function testSoftDeletesCreatesDeletedAt(): void
    {
        $schema = $this->db->schema();

        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->softDeletes();
        });

        self::assertTrue($schema->hasColumn('articles', 'deleted_at'));
    }
}

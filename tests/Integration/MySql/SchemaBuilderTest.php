<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
final class SchemaBuilderTest extends MySqlTestCase
{
    public function testCreateTableWithVariousColumnTypes(): void
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
            $table->enum('status', ['active', 'inactive', 'pending']);
            $table->uuid('ref_uuid')->nullable();
        });

        self::assertTrue($schema->hasTable('test_types'));
    }

    public function testHasTableReturnsTrueForExistingTable(): void
    {
        $this->createUsersTable();

        self::assertTrue($this->db->schema()->hasTable('users'));
    }

    public function testHasTableReturnsFalseForNonExisting(): void
    {
        self::assertFalse($this->db->schema()->hasTable('nonexistent_table'));
    }

    public function testHasColumnChecksColumnExistence(): void
    {
        $this->createUsersTable();

        $schema = $this->db->schema();
        self::assertTrue($schema->hasColumn('users', 'name'));
        self::assertTrue($schema->hasColumn('users', 'email'));
        self::assertFalse($schema->hasColumn('users', 'nonexistent'));
    }

    public function testGetColumnListingReturnsColumnNames(): void
    {
        $this->createUsersTable();

        $columns = $this->db->schema()->getColumnListing('users');

        self::assertContains('id', $columns);
        self::assertContains('name', $columns);
        self::assertContains('email', $columns);
        self::assertContains('age', $columns);
    }

    public function testDropTable(): void
    {
        $this->createUsersTable();
        $schema = $this->db->schema();

        self::assertTrue($schema->hasTable('users'));

        $schema->drop('users');

        self::assertFalse($schema->hasTable('users'));
    }

    public function testDropIfExistsOnNonExistingTableDoesNotError(): void
    {
        $schema = $this->db->schema();

        // Should not throw
        $schema->dropIfExists('totally_nonexistent_table');

        self::assertFalse($schema->hasTable('totally_nonexistent_table'));
    }

    public function testRenameTable(): void
    {
        $this->createUsersTable();
        $schema = $this->db->schema();

        $schema->rename('users', 'members');

        self::assertFalse($schema->hasTable('users'));
        self::assertTrue($schema->hasTable('members'));
    }

    public function testAlterTableAddColumn(): void
    {
        $this->createUsersTable();
        $schema = $this->db->schema();

        $schema->table('users', function (Blueprint $table): void {
            $table->string('nickname', 100)->nullable();
        });

        self::assertTrue($schema->hasColumn('users', 'nickname'));
    }

    public function testAlterTableDropColumn(): void
    {
        $this->createUsersTable();
        $schema = $this->db->schema();

        $schema->table('users', function (Blueprint $table): void {
            $table->dropColumn('deleted_at');
        });

        self::assertFalse($schema->hasColumn('users', 'deleted_at'));
    }

    public function testCreateIndex(): void
    {
        $schema = $this->db->schema();

        $schema->create('indexed_table', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->index('name');
        });

        self::assertTrue($schema->hasTable('indexed_table'));
        // Verify index exists by querying SHOW INDEX
        $indexes = $this->db->select("SHOW INDEX FROM `indexed_table` WHERE Key_name != 'PRIMARY'");
        self::assertGreaterThanOrEqual(1, $indexes->count());
    }

    public function testCreateUniqueIndex(): void
    {
        $schema = $this->db->schema();

        $schema->create('unique_table', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->unique('code');
        });

        self::assertTrue($schema->hasTable('unique_table'));
        $indexes = $this->db->select("SHOW INDEX FROM `unique_table` WHERE Non_unique = 0 AND Key_name != 'PRIMARY'");
        self::assertGreaterThanOrEqual(1, $indexes->count());
    }

    public function testCreateForeignKey(): void
    {
        $this->createUsersTable();
        $schema = $this->db->schema();

        $schema->create('comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        self::assertTrue($schema->hasTable('comments'));

        // Verify FK by inserting with invalid user_id
        $inserted = false;
        try {
            $this->db->table('comments')->insert(['user_id' => 9999, 'body' => 'test']);
            $inserted = true;
        } catch (\Throwable) {
            // expected FK violation
        }
        self::assertFalse($inserted, 'Foreign key constraint should prevent insert with invalid user_id');
    }

    public function testTimestampsAndSoftDeletes(): void
    {
        $schema = $this->db->schema();

        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        $columns = $schema->getColumnListing('articles');
        self::assertContains('created_at', $columns);
        self::assertContains('updated_at', $columns);
        self::assertContains('deleted_at', $columns);
    }
}

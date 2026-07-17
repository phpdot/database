<?php

declare(strict_types=1);

use PHPdot\Database\Migration\Migration;
use PHPdot\Database\Schema\Blueprint;
use PHPdot\Database\Schema\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $schema->create('test_migration_table', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('test_migration_table');
    }
};

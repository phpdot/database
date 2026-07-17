<?php

declare(strict_types=1);

/**
 * Abstract base class for database migrations.
 *
 * Each migration defines an up() method to apply changes and a down()
 * method to reverse them.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Migration;

use PHPdot\Database\Schema\SchemaBuilder;

abstract class Migration
{
    /**
     * Run the migration.
     *
     * @param SchemaBuilder $schema The schema builder for DDL operations
     *
     * @return void
     */
    abstract public function up(SchemaBuilder $schema): void;

    /**
     * Reverse the migration.
     *
     * @param SchemaBuilder $schema The schema builder for DDL operations
     *
     * @return void
     */
    abstract public function down(SchemaBuilder $schema): void;
}

<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Migration;

use PHPdot\Database\Exception\MigrationException;

/**
 * Generates migration file stubs.
 *
 * Creates timestamped migration files with a standard template
 * for defining up() and down() methods.
 */
final class MigrationCreator
{
    /**
     * Create a new migration file.
     *
     * @param string $name The migration name (e.g. "create_users_table")
     * @param string $path The directory to create the file in
     * @param string $table The table name for the migration stub
     * @param bool $create Whether this is a create table migration (vs. alter)
     * @throws MigrationException When the file cannot be created
     * @return string The full path to the created migration file
     */
    public function create(string $name, string $path, string $table = '', bool $create = false): string
    {
        $fileName = $this->getFileName($name);
        $filePath = rtrim($path, '/') . '/' . $fileName . '.php';

        if (file_exists($filePath)) {
            throw MigrationException::migrationFailed($name, 'Migration file already exists: ' . $filePath);
        }

        $stub = $this->getStub($table, $create);

        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $result = file_put_contents($filePath, $stub);

        if ($result === false) {
            throw MigrationException::migrationFailed($name, 'Failed to write migration file: ' . $filePath);
        }

        return $filePath;
    }

    /**
     * Generate a timestamped file name for the migration.
     *
     * @param string $name The migration name
     */
    private function getFileName(string $name): string
    {
        return date('Y_m_d_His') . '_' . $name;
    }

    /**
     * Get the migration stub content.
     *
     * @param string $table The table name
     * @param bool $create Whether this is a create migration
     */
    private function getStub(string $table, bool $create): string
    {
        if ($create && $table !== '') {
            return $this->getCreateStub($table);
        }

        if ($table !== '') {
            return $this->getAlterStub($table);
        }

        return $this->getBlankStub();
    }

    /**
     * Get a create table migration stub.
     *
     * @param string $table The table name
     */
    private function getCreateStub(string $table): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use PHPdot\\Database\\Migration\\Migration;
            use PHPdot\\Database\\Schema\\Blueprint;
            use PHPdot\\Database\\Schema\\SchemaBuilder;

            return new class extends Migration
            {
                public function up(SchemaBuilder \$schema): void
                {
                    \$schema->create('{$table}', static function (Blueprint \$table): void {
                        \$table->id();
                        \$table->timestamps();
                    });
                }

                public function down(SchemaBuilder \$schema): void
                {
                    \$schema->dropIfExists('{$table}');
                }
            };

            PHP;
    }

    /**
     * Get an alter table migration stub.
     *
     * @param string $table The table name
     */
    private function getAlterStub(string $table): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use PHPdot\\Database\\Migration\\Migration;
            use PHPdot\\Database\\Schema\\Blueprint;
            use PHPdot\\Database\\Schema\\SchemaBuilder;

            return new class extends Migration
            {
                public function up(SchemaBuilder \$schema): void
                {
                    \$schema->table('{$table}', static function (Blueprint \$table): void {
                        //
                    });
                }

                public function down(SchemaBuilder \$schema): void
                {
                    \$schema->table('{$table}', static function (Blueprint \$table): void {
                        //
                    });
                }
            };

            PHP;
    }

    /**
     * Get a blank migration stub.
     */
    private function getBlankStub(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use PHPdot\Database\Migration\Migration;
            use PHPdot\Database\Schema\SchemaBuilder;

            return new class extends Migration
            {
                public function up(SchemaBuilder $schema): void
                {
                    //
                }

                public function down(SchemaBuilder $schema): void
                {
                    //
                }
            };

            PHP;
    }
}

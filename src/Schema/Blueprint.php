<?php

declare(strict_types=1);

/**
 * Schema blueprint for defining table structure.
 *
 * Stores columns, indexes, foreign keys, and DDL commands. Passed to
 * a SchemaGrammar to compile into SQL statements.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema;

final class Blueprint
{
    /**
     * @var list<ColumnDefinition>
     */
    private array $columns = [];

    /**
     * @var list<IndexDefinition>
     */
    private array $indexes = [];

    /**
     * @var list<ForeignKeyDefinition>
     */
    private array $foreignKeys = [];

    /**
     * @var list<array{type: string, data: array<string, mixed>}>
     */
    private array $commands = [];

    private string $engine = '';

    private string $charset = '';

    private string $collation = '';

    private string $tableComment = '';

    private bool $temporary = false;

    /**
     * Start a blueprint for the named table.
     *
     * @param string $table The table name
     */
    public function __construct(
        private readonly string $table,
    ) {}

    /**
     * Create an auto-incrementing BIGINT UNSIGNED primary key column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function id(string $column = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($column);
    }

    /**
     * Create an auto-incrementing BIGINT UNSIGNED primary key column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function bigIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedBigInteger($column)->autoIncrement()->primary();
    }

    /**
     * Create an auto-incrementing INT UNSIGNED primary key column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function increments(string $column): ColumnDefinition
    {
        return $this->unsignedInteger($column)->autoIncrement()->primary();
    }

    /**
     * Create an auto-incrementing SMALLINT UNSIGNED primary key column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function smallIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedSmallInteger($column)->autoIncrement()->primary();
    }

    /**
     * Create an auto-incrementing TINYINT UNSIGNED primary key column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function tinyIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedTinyInteger($column)->autoIncrement()->primary();
    }

    /**
     * Create an auto-incrementing MEDIUMINT UNSIGNED primary key column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function mediumIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedMediumInteger($column)->autoIncrement()->primary();
    }

    /**
     * Create a BIGINT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function bigInteger(string $column): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $column);
    }

    /**
     * Create an UNSIGNED BIGINT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function unsignedBigInteger(string $column): ColumnDefinition
    {
        return $this->bigInteger($column)->unsigned();
    }

    /**
     * Create an INT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function integer(string $column): ColumnDefinition
    {
        return $this->addColumn('integer', $column);
    }

    /**
     * Create an UNSIGNED INT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function unsignedInteger(string $column): ColumnDefinition
    {
        return $this->integer($column)->unsigned();
    }

    /**
     * Create a MEDIUMINT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function mediumInteger(string $column): ColumnDefinition
    {
        return $this->addColumn('mediumInteger', $column);
    }

    /**
     * Create an UNSIGNED MEDIUMINT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function unsignedMediumInteger(string $column): ColumnDefinition
    {
        return $this->mediumInteger($column)->unsigned();
    }

    /**
     * Create a SMALLINT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function smallInteger(string $column): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $column);
    }

    /**
     * Create an UNSIGNED SMALLINT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function unsignedSmallInteger(string $column): ColumnDefinition
    {
        return $this->smallInteger($column)->unsigned();
    }

    /**
     * Create a TINYINT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function tinyInteger(string $column): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $column);
    }

    /**
     * Create an UNSIGNED TINYINT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function unsignedTinyInteger(string $column): ColumnDefinition
    {
        return $this->tinyInteger($column)->unsigned();
    }

    /**
     * Create a FLOAT column.
     *
     * @param string $column The column name
     * @param int $precision The total number of digits
     * @param int $scale The number of digits after the decimal point
     *
     * @return ColumnDefinition
     */
    public function float(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('float', $column, ['precision' => $precision, 'scale' => $scale]);
    }

    /**
     * Create a DOUBLE column.
     *
     * @param string $column The column name
     * @param int $precision The total number of digits
     * @param int $scale The number of digits after the decimal point
     *
     * @return ColumnDefinition
     */
    public function double(string $column, int $precision = 16, int $scale = 8): ColumnDefinition
    {
        return $this->addColumn('double', $column, ['precision' => $precision, 'scale' => $scale]);
    }

    /**
     * Create a DECIMAL column.
     *
     * @param string $column The column name
     * @param int $precision The total number of digits
     * @param int $scale The number of digits after the decimal point
     *
     * @return ColumnDefinition
     */
    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $column, ['precision' => $precision, 'scale' => $scale]);
    }

    /**
     * Create an UNSIGNED DECIMAL column.
     *
     * @param string $column The column name
     * @param int $precision The total number of digits
     * @param int $scale The number of digits after the decimal point
     *
     * @return ColumnDefinition
     */
    public function unsignedDecimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->decimal($column, $precision, $scale)->unsigned();
    }

    /**
     * Create a VARCHAR column.
     *
     * @param string $column The column name
     * @param int $length The maximum character length
     *
     * @return ColumnDefinition
     */
    public function string(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $column, ['length' => $length]);
    }

    /**
     * Create a CHAR column.
     *
     * @param string $column The column name
     * @param int $length The fixed character length
     *
     * @return ColumnDefinition
     */
    public function char(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('char', $column, ['length' => $length]);
    }

    /**
     * Create a TEXT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function text(string $column): ColumnDefinition
    {
        return $this->addColumn('text', $column);
    }

    /**
     * Create a MEDIUMTEXT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function mediumText(string $column): ColumnDefinition
    {
        return $this->addColumn('mediumText', $column);
    }

    /**
     * Create a LONGTEXT column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function longText(string $column): ColumnDefinition
    {
        return $this->addColumn('longText', $column);
    }

    /**
     * Create a BOOLEAN column (TINYINT(1)).
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function boolean(string $column): ColumnDefinition
    {
        return $this->addColumn('boolean', $column);
    }

    /**
     * Create a DATE column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function date(string $column): ColumnDefinition
    {
        return $this->addColumn('date', $column);
    }

    /**
     * Create a DATETIME column.
     *
     * @param string $column The column name
     * @param int $precision Fractional seconds precision
     *
     * @return ColumnDefinition
     */
    public function dateTime(string $column, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('dateTime', $column, ['precision' => $precision]);
    }

    /**
     * Create a TIMESTAMP column.
     *
     * @param string $column The column name
     * @param int $precision Fractional seconds precision
     *
     * @return ColumnDefinition
     */
    public function timestamp(string $column, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('timestamp', $column, ['precision' => $precision]);
    }

    /**
     * Create a TIME column.
     *
     * @param string $column The column name
     * @param int $precision Fractional seconds precision
     *
     * @return ColumnDefinition
     */
    public function time(string $column, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('time', $column, ['precision' => $precision]);
    }

    /**
     * Create a YEAR column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function year(string $column): ColumnDefinition
    {
        return $this->addColumn('year', $column);
    }

    /**
     * Create a BINARY column.
     *
     * @param string $column The column name
     * @param int $length The fixed byte length
     *
     * @return ColumnDefinition
     */
    public function binary(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('binary', $column, ['length' => $length]);
    }

    /**
     * Create a BLOB column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function blob(string $column): ColumnDefinition
    {
        return $this->addColumn('blob', $column);
    }

    /**
     * Create a JSON column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function json(string $column): ColumnDefinition
    {
        return $this->addColumn('json', $column);
    }

    /**
     * Create a JSONB column.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function jsonb(string $column): ColumnDefinition
    {
        return $this->addColumn('jsonb', $column);
    }

    /**
     * Create an ENUM column.
     *
     * @param string $column The column name
     * @param list<string> $allowed The allowed values
     *
     * @return ColumnDefinition
     */
    public function enum(string $column, array $allowed): ColumnDefinition
    {
        return $this->addColumn('enum', $column, ['allowed' => $allowed]);
    }

    /**
     * Create a SET column.
     *
     * @param string $column The column name
     * @param list<string> $allowed The allowed values
     *
     * @return ColumnDefinition
     */
    public function set(string $column, array $allowed): ColumnDefinition
    {
        return $this->addColumn('set', $column, ['allowed' => $allowed]);
    }

    /**
     * Create a UUID column (CHAR(36)).
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function uuid(string $column = 'uuid'): ColumnDefinition
    {
        return $this->addColumn('uuid', $column);
    }

    /**
     * Create a ULID column (CHAR(26)).
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function ulid(string $column = 'ulid'): ColumnDefinition
    {
        return $this->addColumn('char', $column, ['length' => 26]);
    }

    /**
     * Create an IP address column (VARCHAR(45)).
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function ipAddress(string $column = 'ip_address'): ColumnDefinition
    {
        return $this->addColumn('ipAddress', $column);
    }

    /**
     * Create a MAC address column (VARCHAR(17)).
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function macAddress(string $column = 'mac_address'): ColumnDefinition
    {
        return $this->addColumn('macAddress', $column);
    }

    /**
     * Add nullable created_at and updated_at TIMESTAMP columns.
     *
     * @param int $precision Fractional seconds precision
     *
     * @return void
     */
    public function timestamps(int $precision = 0): void
    {
        $this->timestamp('created_at', $precision)->nullable()->useCurrent();
        $this->timestamp('updated_at', $precision)->nullable()->useCurrent()->useCurrentOnUpdate();
    }

    /**
     * Add a nullable deleted_at TIMESTAMP column for soft deletes.
     *
     * @param string $column The column name
     * @param int $precision Fractional seconds precision
     *
     * @return ColumnDefinition
     */
    public function softDeletes(string $column = 'deleted_at', int $precision = 0): ColumnDefinition
    {
        return $this->timestamp($column, $precision)->nullable();
    }

    /**
     * Add polymorphic morph columns ({name}_type and {name}_id).
     *
     * @param string $name The morph name prefix
     * @param string $indexName Optional index name
     *
     * @return void
     */
    public function morphs(string $name, string $indexName = ''): void
    {
        $this->string($name . '_type');
        $this->unsignedBigInteger($name . '_id');
        $this->index(
            [$name . '_type', $name . '_id'],
            $indexName !== '' ? $indexName : null,
        );
    }

    /**
     * Add nullable polymorphic morph columns.
     *
     * @param string $name The morph name prefix
     * @param string $indexName Optional index name
     *
     * @return void
     */
    public function nullableMorphs(string $name, string $indexName = ''): void
    {
        $this->string($name . '_type')->nullable();
        $this->unsignedBigInteger($name . '_id')->nullable();
        $this->index(
            [$name . '_type', $name . '_id'],
            $indexName !== '' ? $indexName : null,
        );
    }

    /**
     * Add a remember_token VARCHAR(100) column.
     *
     * @return ColumnDefinition
     */
    public function rememberToken(): ColumnDefinition
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * Add an UNSIGNED BIGINT column as a foreign key reference.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function foreignId(string $column): ColumnDefinition
    {
        return $this->unsignedBigInteger($column);
    }

    /**
     * Add a UUID column as a foreign key reference.
     *
     * @param string $column The column name
     *
     * @return ColumnDefinition
     */
    public function foreignUuid(string $column): ColumnDefinition
    {
        return $this->uuid($column);
    }

    /**
     * Add a primary key index.
     *
     * @param string|list<string> $columns The column(s) for the primary key
     * @param string|null $name Optional index name
     *
     * @return IndexDefinition
     */
    public function primary(string|array $columns, ?string $name = null): IndexDefinition
    {
        return $this->addIndex('primary', $columns, $name);
    }

    /**
     * Add a unique index.
     *
     * @param string|list<string> $columns The column(s) for the unique index
     * @param string|null $name Optional index name
     *
     * @return IndexDefinition
     */
    public function unique(string|array $columns, ?string $name = null): IndexDefinition
    {
        return $this->addIndex('unique', $columns, $name);
    }

    /**
     * Add a regular index.
     *
     * @param string|list<string> $columns The column(s) for the index
     * @param string|null $name Optional index name
     *
     * @return IndexDefinition
     */
    public function index(string|array $columns, ?string $name = null): IndexDefinition
    {
        return $this->addIndex('index', $columns, $name);
    }

    /**
     * Add a fulltext index.
     *
     * @param string|list<string> $columns The column(s) for the fulltext index
     * @param string|null $name Optional index name
     *
     * @return IndexDefinition
     */
    public function fullText(string|array $columns, ?string $name = null): IndexDefinition
    {
        return $this->addIndex('fulltext', $columns, $name);
    }

    /**
     * Add a spatial index.
     *
     * @param string|list<string> $columns The column(s) for the spatial index
     * @param string|null $name Optional index name
     *
     * @return IndexDefinition
     */
    public function spatialIndex(string|array $columns, ?string $name = null): IndexDefinition
    {
        return $this->addIndex('spatial', $columns, $name);
    }

    /**
     * Add a foreign key constraint.
     *
     * @param string $column The local column name
     *
     * @return ForeignKeyDefinition
     */
    public function foreign(string $column): ForeignKeyDefinition
    {
        $foreignKey = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = $foreignKey;

        return $foreignKey;
    }

    /**
     * Drop one or more columns from the table.
     *
     * @param string|list<string> $columns The column(s) to drop
     *
     * @return void
     */
    public function dropColumn(string|array $columns): void
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        $this->commands[] = ['type' => 'dropColumn', 'data' => ['columns' => $columns]];
    }

    /**
     * Rename a column.
     *
     * @param string $from The current column name
     * @param string $to The new column name
     *
     * @return void
     */
    public function renameColumn(string $from, string $to): void
    {
        $this->commands[] = ['type' => 'renameColumn', 'data' => ['from' => $from, 'to' => $to]];
    }

    /**
     * Drop an index by name.
     *
     * @param string $name The index name
     *
     * @return void
     */
    public function dropIndex(string $name): void
    {
        $this->commands[] = ['type' => 'dropIndex', 'data' => ['name' => $name]];
    }

    /**
     * Drop a unique index by name.
     *
     * @param string $name The unique index name
     *
     * @return void
     */
    public function dropUnique(string $name): void
    {
        $this->commands[] = ['type' => 'dropUnique', 'data' => ['name' => $name]];
    }

    /**
     * Drop the primary key.
     *
     * @return void
     */
    public function dropPrimary(): void
    {
        $this->commands[] = ['type' => 'dropPrimary', 'data' => []];
    }

    /**
     * Drop a foreign key constraint by name.
     *
     * @param string $name The foreign key constraint name
     *
     * @return void
     */
    public function dropForeign(string $name): void
    {
        $this->commands[] = ['type' => 'dropForeign', 'data' => ['name' => $name]];
    }

    /**
     * Drop timestamps columns (created_at and updated_at).
     *
     * @return void
     */
    public function dropTimestamps(): void
    {
        $this->dropColumn(['created_at', 'updated_at']);
    }

    /**
     * Drop the soft deletes column.
     *
     * @param string $column The soft delete column name
     *
     * @return void
     */
    public function dropSoftDeletes(string $column = 'deleted_at'): void
    {
        $this->dropColumn($column);
    }

    /**
     * Drop morph columns.
     *
     * @param string $name The morph name prefix
     *
     * @return void
     */
    public function dropMorphs(string $name): void
    {
        $this->dropColumn([$name . '_type', $name . '_id']);
    }

    /**
     * Set the storage engine (MySQL).
     *
     * @param string $engine The storage engine (InnoDB, MyISAM, etc.)
     *
     * @return void
     */
    public function engine(string $engine): void
    {
        $this->engine = $engine;
    }

    /**
     * Set the default character set.
     *
     * @param string $charset The character set
     *
     * @return void
     */
    public function charset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * Set the default collation.
     *
     * @param string $collation The collation
     *
     * @return void
     */
    public function collation(string $collation): void
    {
        $this->collation = $collation;
    }

    /**
     * Set a table comment.
     *
     * @param string $comment The table comment
     *
     * @return void
     */
    public function comment(string $comment): void
    {
        $this->tableComment = $comment;
    }

    /**
     * Mark the table as temporary.
     *
     * @return void
     */
    public function temporary(): void
    {
        $this->temporary = true;
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get all column definitions.
     *
     * @return list<ColumnDefinition>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get all index definitions.
     *
     * @return list<IndexDefinition>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Get all foreign key definitions.
     *
     * @return list<ForeignKeyDefinition>
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * Get all DDL commands.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Get the storage engine.
     *
     * @return string
     */
    public function getEngine(): string
    {
        return $this->engine;
    }

    /**
     * Get the character set.
     *
     * @return string
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * Get the collation.
     *
     * @return string
     */
    public function getCollation(): string
    {
        return $this->collation;
    }

    /**
     * Get the table comment.
     *
     * @return string
     */
    public function getTableComment(): string
    {
        return $this->tableComment;
    }

    /**
     * Check if the table is temporary.
     *
     * @return bool
     */
    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    /**
     * Add a column definition to the blueprint.
     *
     * @param string $type The column type
     * @param string $name The column name
     * @param array<string, mixed> $parameters Additional type-specific parameters
     *
     * @return ColumnDefinition
     */
    private function addColumn(string $type, string $name, array $parameters = []): ColumnDefinition
    {
        $column = new ColumnDefinition($type, $name, $parameters);
        $this->columns[] = $column;

        return $column;
    }

    /**
     * Add an index definition to the blueprint.
     *
     * @param string $type The index type
     * @param string|list<string> $columns The column(s) for the index
     * @param string|null $name Optional index name
     *
     * @return IndexDefinition
     */
    private function addIndex(string $type, string|array $columns, ?string $name = null): IndexDefinition
    {
        $index = new IndexDefinition($type, $columns, $name);
        $this->indexes[] = $index;

        return $index;
    }
}

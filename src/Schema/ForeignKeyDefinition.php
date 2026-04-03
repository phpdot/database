<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema;

/**
 * Fluent builder for foreign key constraints in a schema blueprint.
 *
 * Each fluent method configures the foreign key and returns self for chaining.
 */
final class ForeignKeyDefinition
{
    private string $referencedTable = '';

    /** @var string|list<string> */
    private string|array $referencedColumns = '';

    private string $onDelete = '';

    private string $onUpdate = '';

    /**
     * @param string $column The local column that references another table
     */
    public function __construct(
        private readonly string $column,
    ) {}

    /**
     * Set the referenced column(s) on the foreign table.
     *
     * @param string|list<string> $columns The referenced column(s)
     */
    public function references(string|array $columns): self
    {
        $this->referencedColumns = $columns;

        return $this;
    }

    /**
     * Set the referenced table.
     *
     * @param string $table The referenced table name
     */
    public function on(string $table): self
    {
        $this->referencedTable = $table;

        return $this;
    }

    /**
     * Set the ON DELETE action.
     *
     * @param string $action The action (CASCADE, RESTRICT, SET NULL, NO ACTION)
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = $action;

        return $this;
    }

    /**
     * Set the ON UPDATE action.
     *
     * @param string $action The action (CASCADE, RESTRICT, SET NULL, NO ACTION)
     */
    public function onUpdate(string $action): self
    {
        $this->onUpdate = $action;

        return $this;
    }

    /**
     * Set ON DELETE CASCADE.
     */
    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Set ON UPDATE CASCADE.
     */
    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Set ON DELETE RESTRICT.
     */
    public function restrictOnDelete(): self
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * Set ON DELETE SET NULL.
     */
    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * Set ON DELETE NO ACTION.
     */
    public function noActionOnDelete(): self
    {
        return $this->onDelete('NO ACTION');
    }

    /**
     * Get the local column name.
     */
    public function getColumn(): string
    {
        return $this->column;
    }

    /**
     * Get the referenced table name.
     */
    public function getReferencedTable(): string
    {
        return $this->referencedTable;
    }

    /**
     * Get the referenced column(s).
     *
     * @return string|list<string>
     */
    public function getReferencedColumns(): string|array
    {
        return $this->referencedColumns;
    }

    /**
     * Get the ON DELETE action.
     */
    public function getOnDelete(): string
    {
        return $this->onDelete;
    }

    /**
     * Get the ON UPDATE action.
     */
    public function getOnUpdate(): string
    {
        return $this->onUpdate;
    }
}

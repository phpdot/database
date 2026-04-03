<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema;

/**
 * Fluent builder for column attributes in a schema blueprint.
 *
 * Each fluent method sets an attribute and returns self for chaining.
 * Used by Blueprint to define column types and modifiers.
 */
final class ColumnDefinition
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param string $type The column type (string, integer, text, etc.)
     * @param string $name The column name
     * @param array<string, mixed> $parameters Additional type-specific parameters
     */
    public function __construct(string $type, string $name, array $parameters = [])
    {
        $this->attributes = array_merge(['type' => $type, 'name' => $name], $parameters);
    }

    /**
     * Allow NULL values.
     *
     * @param bool $value Whether the column is nullable
     */
    public function nullable(bool $value = true): self
    {
        $this->attributes['nullable'] = $value;

        return $this;
    }

    /**
     * Set a default value for the column.
     *
     * @param mixed $value The default value
     */
    public function default(mixed $value): self
    {
        $this->attributes['default'] = $value;

        return $this;
    }

    /**
     * Mark the column as UNSIGNED.
     */
    public function unsigned(): self
    {
        $this->attributes['unsigned'] = true;

        return $this;
    }

    /**
     * Mark the column as AUTO_INCREMENT.
     */
    public function autoIncrement(): self
    {
        $this->attributes['autoIncrement'] = true;

        return $this;
    }

    /**
     * Mark the column as PRIMARY KEY.
     */
    public function primary(): self
    {
        $this->attributes['primary'] = true;

        return $this;
    }

    /**
     * Add a UNIQUE constraint to the column.
     */
    public function unique(): self
    {
        $this->attributes['unique'] = true;

        return $this;
    }

    /**
     * Add an INDEX to the column.
     */
    public function index(): self
    {
        $this->attributes['index'] = true;

        return $this;
    }

    /**
     * Set a comment on the column.
     *
     * @param string $comment The column comment
     */
    public function comment(string $comment): self
    {
        $this->attributes['comment'] = $comment;

        return $this;
    }

    /**
     * Place the column after another column (MySQL).
     *
     * @param string $column The column to place after
     */
    public function after(string $column): self
    {
        $this->attributes['after'] = $column;

        return $this;
    }

    /**
     * Place the column first in the table (MySQL).
     */
    public function first(): self
    {
        $this->attributes['first'] = true;

        return $this;
    }

    /**
     * Set the column character set.
     *
     * @param string $charset The character set
     */
    public function charset(string $charset): self
    {
        $this->attributes['charset'] = $charset;

        return $this;
    }

    /**
     * Set the column collation.
     *
     * @param string $collation The collation
     */
    public function collation(string $collation): self
    {
        $this->attributes['collation'] = $collation;

        return $this;
    }

    /**
     * Set the default value to CURRENT_TIMESTAMP.
     */
    public function useCurrent(): self
    {
        $this->attributes['useCurrent'] = true;

        return $this;
    }

    /**
     * Set ON UPDATE CURRENT_TIMESTAMP.
     */
    public function useCurrentOnUpdate(): self
    {
        $this->attributes['useCurrentOnUpdate'] = true;

        return $this;
    }

    /**
     * Create a stored generated column.
     *
     * @param string $expression The generation expression
     */
    public function storedAs(string $expression): self
    {
        $this->attributes['storedAs'] = $expression;

        return $this;
    }

    /**
     * Create a virtual generated column.
     *
     * @param string $expression The generation expression
     */
    public function virtualAs(string $expression): self
    {
        $this->attributes['virtualAs'] = $expression;

        return $this;
    }

    /**
     * Mark this column definition as a column modification (ALTER TABLE MODIFY).
     */
    public function change(): self
    {
        $this->attributes['change'] = true;

        return $this;
    }

    /**
     * Get all column attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get a single attribute value.
     *
     * @param string $key The attribute key
     * @param mixed $default The default value if the attribute is not set
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get a string attribute value.
     *
     * @param string $key The attribute key
     * @param string $default The default value if the attribute is not set
     */
    public function getStringAttribute(string $key, string $default = ''): string
    {
        $value = $this->attributes[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Get an integer attribute value.
     *
     * @param string $key The attribute key
     * @param int $default The default value if the attribute is not set
     */
    public function getIntAttribute(string $key, int $default = 0): int
    {
        $value = $this->attributes[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }
}

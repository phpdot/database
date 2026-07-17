<?php

declare(strict_types=1);

/**
 * Defines an index on a schema blueprint.
 *
 * Supports primary, unique, index, fulltext, and spatial index types.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Schema;

final class IndexDefinition
{
    private string $algorithm = '';

    private string $language = '';

    /**
     * Define an index of the given type over one or more columns.
     *
     * @param string $type The index type (primary, unique, index, fulltext, spatial)
     * @param string|list<string> $columns The column(s) in the index
     * @param string|null $name Optional index name
     */
    public function __construct(
        private readonly string $type,
        private readonly string|array $columns,
        private readonly ?string $name = null,
    ) {}

    /**
     * Set the index algorithm (BTREE, HASH, etc.).
     *
     * @param string $algorithm The index algorithm
     *
     * @return self
     */
    public function algorithm(string $algorithm): self
    {
        $this->algorithm = $algorithm;

        return $this;
    }

    /**
     * Set the fulltext search language.
     *
     * @param string $language The language for fulltext indexing
     *
     * @return IndexDefinition
     */
    public function language(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Get the index type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the indexed column(s).
     *
     * @return string|list<string>
     */
    public function getColumns(): string|array
    {
        return $this->columns;
    }

    /**
     * Get the optional index name.
     *
     * @return ?string
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get the index algorithm.
     *
     * @return string
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Get the fulltext search language.
     *
     * @return string
     */
    public function getLanguage(): string
    {
        return $this->language;
    }
}

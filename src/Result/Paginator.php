<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Result;

/**
 * Offset-based paginator for database query results.
 *
 * Holds a page of items along with total count and pagination metadata.
 */
final readonly class Paginator
{
    /**
     * @param list<array<string, mixed>> $items The items for the current page
     * @param int $total The total number of items across all pages
     * @param int $perPage The number of items per page
     * @param int $currentPage The current page number (1-based)
     */
    public function __construct(
        private array $items,
        private int $total,
        private int $perPage,
        private int $currentPage,
    ) {}

    /**
     * Get the items for the current page.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Get the total number of items.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Get the number of items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the current page number.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get the last page number.
     */
    public function lastPage(): int
    {
        if ($this->perPage <= 0) {
            return 1;
        }

        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /**
     * Check if there are more pages after the current one.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /**
     * Check if the result set is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Check if the result set is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * Get the number of items on the current page.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Get the index of the first item on the current page (1-based).
     */
    public function firstItem(): ?int
    {
        if ($this->items === []) {
            return null;
        }

        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    /**
     * Get the index of the last item on the current page (1-based).
     */
    public function lastItem(): ?int
    {
        $first = $this->firstItem();

        if ($first === null) {
            return null;
        }

        return $first + $this->count() - 1;
    }

    /**
     * Convert the paginator to an array.
     *
     * @return array{items: list<array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int, has_more: bool}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage(),
            'has_more' => $this->hasMorePages(),
        ];
    }

    /**
     * Convert the paginator to a JSON string.
     *
     * @param int $options JSON encoding options bitmask
     */
    public function toJson(int $options = 0): string
    {
        $json = json_encode($this->toArray(), $options);

        if ($json === false) {
            return '{}';
        }

        return $json;
    }
}

<?php

declare(strict_types=1);

/**
 * Offset-based paginator for database query results.
 *
 * Holds a page of items along with total count and pagination metadata.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Result;

final readonly class Paginator
{
    /**
     * Assemble an offset-paginated view over a page of items.
     *
     * @param list<array<string, mixed>> $items The items for the current page
     * @param int $total The total number of items across all pages (-1 when unknown, e.g. simple pagination)
     * @param int $perPage The number of items per page
     * @param int $currentPage The current page number (1-based)
     * @param bool|null $hasMore Explicit "has more pages" flag; null derives it from the total
     */
    public function __construct(
        private array $items,
        private int $total,
        private int $perPage,
        private int $currentPage,
        private ?bool $hasMore = null,
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
     *
     * @return int
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Get the number of items per page.
     *
     * @return int
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the current page number.
     *
     * @return int
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get the last page number.
     *
     * @return int
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
     *
     * @return bool
     */
    public function hasMorePages(): bool
    {
        if ($this->hasMore !== null) {
            return $this->hasMore;
        }

        return $this->currentPage < $this->lastPage();
    }

    /**
     * Check if the result set is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Check if the result set is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * Get the number of items on the current page.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Get the index of the first item on the current page (1-based).
     *
     * @return ?int
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
     *
     * @return ?int
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
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     per_page: int,
     *     current_page: int,
     *     last_page: int,
     *     has_more: bool,
     * }
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
     *
     * @return string
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

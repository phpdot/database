<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Result;

/**
 * Cursor-based paginator for efficient pagination of large result sets.
 *
 * Uses an opaque cursor string instead of page numbers, enabling
 * consistent pagination even when rows are inserted or deleted.
 */
final readonly class CursorPaginator
{
    /**
     * @param list<array<string, mixed>> $items The items for the current page
     * @param int $perPage The number of items per page
     * @param string|null $cursor The current cursor value
     * @param bool $hasMore Whether there are more items after this page
     * @param string|null $nextCursor The cursor for the next page, or null if no more pages
     */
    public function __construct(
        private array $items,
        private int $perPage,
        private ?string $cursor,
        private bool $hasMore,
        private ?string $nextCursor = null,
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
     * Get the number of items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Check if there are more pages.
     */
    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    /**
     * Get the cursor for the next page.
     *
     * Returns null if there are no more pages or the result is empty.
     */
    public function nextCursor(): ?string
    {
        if ($this->nextCursor !== null) {
            return $this->nextCursor;
        }

        if (!$this->hasMore || $this->items === []) {
            return null;
        }

        $lastItem = $this->items[count($this->items) - 1];

        return base64_encode((string) json_encode($lastItem));
    }

    /**
     * Get the current cursor value.
     */
    public function previousCursor(): ?string
    {
        return $this->cursor;
    }

    /**
     * Get the number of items on the current page.
     */
    public function count(): int
    {
        return count($this->items);
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
     * Convert the paginator to an array.
     *
     * @return array{items: list<array<string, mixed>>, per_page: int, has_more: bool, next_cursor: string|null, previous_cursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'per_page' => $this->perPage,
            'has_more' => $this->hasMore,
            'next_cursor' => $this->nextCursor(),
            'previous_cursor' => $this->previousCursor(),
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

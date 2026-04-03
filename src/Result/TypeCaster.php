<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Result;

use DateTimeInterface;

/**
 * Casts database result row values to PHP types.
 *
 * Accepts a map of column names to type strings and applies the
 * appropriate cast to each matching column in a result row.
 */
final class TypeCaster
{
    /**
     * @param array<string, string> $casts Map of column name to type (int, float, bool, string, json, array)
     */
    public function __construct(
        private readonly array $casts = [],
    ) {}

    /**
     * Cast the values in a result row according to the configured casts.
     *
     * @param array<string, mixed> $row The database result row
     * @return array<string, mixed> The row with cast values
     */
    public function cast(array $row): array
    {
        foreach ($this->casts as $column => $type) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $row[$column] = $this->castValue($row[$column], $type);
        }

        return $row;
    }

    /**
     * Cast a single value to the specified type.
     *
     * @param mixed $value The value to cast
     * @param string $type The target type
     */
    private function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int', 'integer' => $this->toInt($value),
            'float', 'double', 'real' => $this->toFloat($value),
            'bool', 'boolean' => $this->toBool($value),
            'string' => $this->toString($value),
            'json', 'array' => $this->castJson($value),
            'datetime' => $this->castDatetime($value),
            default => $value,
        };
    }

    /**
     * Convert a scalar value to int.
     *
     * @param mixed $value The value to convert
     */
    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Convert a scalar value to float.
     *
     * @param mixed $value The value to convert
     */
    private function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * Convert a scalar value to bool.
     *
     * @param mixed $value The value to convert
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (bool) $value;
        }

        return false;
    }

    /**
     * Convert a value to string.
     *
     * @param mixed $value The value to convert
     */
    private function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Cast a value to a decoded JSON array.
     *
     * @param mixed $value The JSON string to decode
     * @return array<int|string, mixed>
     */
    private function castJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Cast a value to a datetime string.
     *
     * @param mixed $value The datetime value
     */
    private function castDatetime(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}

<?php

declare(strict_types=1);

/**
 * Coercion and validation helpers for turning a raw connection parameter block
 * (untyped config array) into typed values, with fail-fast checks for keys a
 * driver requires.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Connection;

use InvalidArgumentException;

final class ConfigValue
{
    /**
     * Coerce a raw parameter value to a string.
     *
     * @param array<string, mixed> $block
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    public static function string(array $block, string $key, string $default): string
    {
        $value = $block[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Coerce a raw parameter value to an integer.
     *
     * @param array<string, mixed> $block
     * @param string $key
     * @param int $default
     *
     * @return int
     */
    public static function int(array $block, string $key, int $default): int
    {
        $value = $block[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Coerce a raw parameter value to a boolean.
     *
     * @param array<string, mixed> $block
     * @param string $key
     * @param bool $default
     *
     * @return bool
     */
    public static function bool(array $block, string $key, bool $default): bool
    {
        $value = $block[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Require a non-empty string value, throwing a descriptive error if absent.
     * This is how a connection block is checked against the driver's real needs.
     *
     * @param array<string, mixed> $block
     * @param string $connection
     * @param string $driver
     * @param string $key
     *
     * @throws InvalidArgumentException When the key is missing or not a non-empty string
     *
     * @return string
     */
    public static function requireString(string $connection, string $driver, array $block, string $key): string
    {
        $value = $block[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(
                "Database connection '{$connection}' ({$driver}) requires a non-empty '{$key}'.",
            );
        }

        return $value;
    }

    /**
     * Extract the read-replica override blocks, failing fast on a malformed
     * 'read' key so a typo cannot silently disable read splitting.
     *
     * @param array<string, mixed> $block
     * @param string $connection
     *
     * @throws InvalidArgumentException When 'read' is present but not a list of arrays
     *
     * @return list<array<string, mixed>>
     */
    public static function replicas(string $connection, array $block): array
    {
        if (!array_key_exists('read', $block)) {
            return [];
        }

        $read = $block['read'];

        if (!is_array($read) || !array_is_list($read)) {
            throw new InvalidArgumentException(
                "Database connection '{$connection}': 'read' must be a list of replica override blocks.",
            );
        }

        $replicas = [];

        foreach ($read as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException(
                    "Database connection '{$connection}': each 'read' entry must be an array of connection overrides.",
                );
            }

            /**
             * @var array<string, mixed> $entry
             */
            $replicas[] = $entry;
        }

        return $replicas;
    }

    /**
     * Extract and validate the driver-options sub-array.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    public static function driverOptions(array $block): array
    {
        $options = $block['options'] ?? [];

        if (!is_array($options)) {
            return [];
        }

        /**
         * @var array<string, mixed> $options
         */
        return $options;
    }
}

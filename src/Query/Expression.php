<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query;

use Stringable;

/**
 * Raw SQL expression that should not be escaped or quoted.
 */
final readonly class Expression implements Stringable
{
    /**
     * @param string $value The raw SQL expression
     */
    public function __construct(
        public string $value,
    ) {}

    /**
     * Get the string representation of the expression.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}

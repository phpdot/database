<?php

declare(strict_types=1);

/**
 * Expression
 *
 * Raw SQL expression that should not be escaped or quoted.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query;

use Stringable;

final readonly class Expression implements Stringable
{
    /**
     * Wrap a raw SQL fragment that must not be quoted.
     *
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

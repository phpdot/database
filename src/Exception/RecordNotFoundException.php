<?php

declare(strict_types=1);

/**
 * Record Not Found Exception
 *
 * Thrown when an expected database record could not be found.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class RecordNotFoundException extends DatabaseException
{
    /**
     * Build an exception for a lookup that matched no rows.
     *
     * @param string $table The table where the record was not found
     *
     * @return self
     */
    public static function recordNotFound(string $table): self
    {
        return new self(
            sprintf('No record found in table "%s"', $table),
        );
    }
}

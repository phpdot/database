<?php

declare(strict_types=1);

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class RecordNotFoundException extends DatabaseException
{
    /**
     * @param string $table The table where the record was not found
     */
    public static function recordNotFound(string $table): self
    {
        return new self(
            sprintf('No record found in table "%s"', $table),
        );
    }
}

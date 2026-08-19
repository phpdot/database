<?php

declare(strict_types=1);

/**
 * Thrown when an opaque pagination cursor cannot be decoded.
 *
 * Mirrors the mongodb package's exception of the same name: a corrupt or
 * hand-edited cursor is a caller error surfaced loudly, never a silent
 * restart from the first page.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Exception;

final class InvalidCursorException extends DatabaseException {}

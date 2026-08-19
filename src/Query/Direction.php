<?php

declare(strict_types=1);

/**
 * Sort direction — the vocabulary of `Builder::orderBy()`.
 *
 * Backed by the two literals every supported grammar spells the same way, so
 * `$direction->value` passes through with nothing to escape. It exists because
 * `orderBy($column, 'descending')` used to be a silent ascending sort: the
 * builder normalises anything that is not 'desc' to ASC, which is the right
 * behaviour for a value off the wire and the wrong one for a developer typo.
 * Passing a case makes that typo a type error instead.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Database\Query;

enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}

<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Xivi\Core\Query;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum Direction: string
{
    case Ascending = 'asc';
    case Descending = 'desc';

    public function opposite(): self
    {
        return $this === self::Ascending ? self::Descending : self::Ascending;
    }

    /**
     * Empty values sort last either way. A record with nothing in a field is not
     * "before everything" or "after everything" — it is missing, and putting it
     * at the end is what a person reading a list expects in both directions.
     */
    public function sql(): string
    {
        return $this === self::Ascending ? 'ASC NULLS LAST' : 'DESC NULLS LAST';
    }
}

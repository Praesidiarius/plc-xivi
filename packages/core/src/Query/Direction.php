<?php

declare(strict_types=1);

namespace Xivi\Core\Query;

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

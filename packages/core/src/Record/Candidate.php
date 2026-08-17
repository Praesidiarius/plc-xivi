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

namespace Xivi\Core\Record;

/**
 * A record a reference could point at: its id, and what it is called (XIV-36).
 *
 * Two fields and no record, on purpose. Whatever offers a candidate — a select's
 * options, a search endpoint's JSON, the choice list that has to accept whatever
 * comes back — needs the id to store and the name to show, and needs nothing
 * else. Handing the whole record around instead would mean three call sites each
 * deciding again what a record is called, which is the duplication
 * {@see RecordTitle} was extracted to end.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Candidate
{
    public function __construct(
        public int $id,
        public string $label,
    ) {
    }
}

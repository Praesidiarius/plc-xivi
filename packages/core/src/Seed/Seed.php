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

namespace Xivi\Core\Seed;

/**
 * "A record of mine is made from a record of theirs" (XIV-19).
 *
 * An invoice is made from an order, a delivery note from an order, an order from
 * a quotation. It is the commonest thing an ERP does and it is always the same
 * thing: copy a header, copy the lines, keep a link back, and never do it twice
 * for the same line.
 *
 * **Copied, never read through.** The new record holds its own values from the
 * moment it exists, which is what lets an invoice stay correct after the order is
 * edited and what lets a second invoice hold different lines from the first. The
 * link is kept beside the copy so reporting still knows where it came from — the
 * same shape as an order line's article (§5.1), one level up.
 *
 * **Seeding is not saving.** What this produces is a *form*, filled in, that
 * somebody looks at and changes before pressing save. An invoice that appeared
 * fully formed the moment a button was pressed would be a document nobody read.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Seed
{
    /** @param array<string, string> $fields this module's field key => the source's */
    public function __construct(
        /** The module a record of this one is made from. Its key, not its package (§3). */
        public string $from,
        /** This module's reference field holding the source record. */
        public string $link,
        public array $fields = [],
        /** The rows to bring along. Null for a document that copies a header and no lines. */
        public ?SeedRows $rows = null,
        /**
         * What the button on the source record is called, as a translation key
         * in *this* module's catalogue — it is this module being offered.
         */
        public string $label = 'seed.action',
    ) {
    }
}

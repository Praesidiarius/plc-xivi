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
 * Which rows come along when a record is made from another, and how much of them
 * is left (XIV-19).
 *
 * **All of them come along, including the ones with no money on them.** A
 * comment line is part of how the document reads, and an invoice that dropped the
 * headings the order confirmation had would look worse than the document the
 * customer already has. A subtotal comes along too and is *recomputed* rather
 * than copied — a partial invoice holds fewer lines than the order, so the
 * order's figure would be the most convincing wrong number in the system. That
 * costs nothing to arrange: the figure is derived on save (§5.9), so copying the
 * line and not its total is the whole of it.
 *
 * **`outstanding` is what makes a second invoice possible.** Each new row records
 * which row it came from, and the quantity offered is what that source row has
 * left after every earlier document took its share. Without it, invoicing an
 * order twice means invoicing it twice over.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SeedRows
{
    /** @param array<string, string> $fields this collection's field key => the source's */
    public function __construct(
        /** The source module's collection to read. */
        public string $from,
        /** This module's collection to fill. */
        public string $to,
        public array $fields,
        /**
         * The field on a new row holding the id of the row it came from.
         *
         * A plain number rather than a `reference`, because a reference points
         * at a *record* and a collection row is not one — it has no page and no
         * life of its own (§5.1). What this is for is arithmetic, not a link
         * somebody follows.
         */
        public string $source,
        /**
         * The quantity field that earlier documents draw down, or null for rows
         * that may be copied over and over — a standing note, say.
         */
        public ?string $outstanding = null,
    ) {
    }
}

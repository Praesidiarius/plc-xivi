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

namespace Xivi\Core\Money;

/**
 * One line of a document, offered to whatever might take something off it
 * (XIV-122).
 *
 * [XIV-104] asked a {@see DocumentDiscounts} one question — *what comes off this
 * document* — and gave it the header's values and one figure to work from. That
 * is everything an **order voucher** needs, because an order voucher is a fact
 * about the document as a whole. A **line voucher** is not: it is applied to one
 * line, chosen when it is applied, and the line is where it is named. So the
 * source has to be able to see the lines, and this is what it sees of them.
 *
 * Three things, and the absences are as deliberate as the presences:
 *
 * - **`index`** — where the line sits in the collection as this save has it. It
 *   is what a per-line answer is keyed by ({@see Discount::$perLine}), and it is
 *   a position rather than a row id **because a row that has never been saved has
 *   no id**. Somebody typing a new line and a voucher onto it in one go is the
 *   ordinary case, not the edge, and an answer keyed by id would silently miss
 *   exactly that line.
 * - **`data`** — the row's values as the save has them. A discount source reads
 *   whatever of them it named in the customer's own definitions: which voucher
 *   this line carries, and which article it sells. Core knows neither key and
 *   passes the row through whole rather than growing an opinion about what a line
 *   is made of.
 * - **`amount`** — what the line charges, quantity times price, rounded exactly
 *   as {@see DerivesTotals} rounds it and **before anything has come off it**.
 *   That is what a percentage is a percentage *of*, and it is also the ceiling a
 *   fixed amount is floored to — neither of which the source could work out for
 *   itself without duplicating the rounding rule §5.9 keeps in one place.
 *
 * **The line's VAT rate is not here**, and it does not need to be: a line
 * discount stays on its own line, so it joins that line's rate by being part of
 * it. That is the whole reason a line voucher needs no apportionment where
 * [XIV-104]'s order voucher needed one line per rate — there is exactly one rate
 * it could possibly come off.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DiscountableLine
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public int $index,
        public array $data,
        public Amount $amount,
    ) {
    }
}

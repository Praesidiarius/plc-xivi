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
 * One line a discount puts on a document, priced by whoever granted it
 * (XIV-104).
 *
 * The other half of {@see Discount}, and the half that needs no apportioning:
 * whatever grants the discount says what this line says, how many of it there
 * are and what each one costs. A free article is the case it exists for — two of
 * something at nothing each — and it is a line rather than a subtraction because
 * that is what a free article *is*: the customer receives it, and the document
 * has to show it being received.
 *
 * **The rate is deliberately absent.** A line at a price of nothing contributes
 * nothing to any rate's base, so which base it would have joined cannot change a
 * figure anywhere on the document, and asking for one would mean core knowing
 * what the article module calls its VAT field. The one thing that would be lost —
 * a VAT table row stating the rate at which nothing was charged — is a row that
 * reads "8.1% of 0.00 = 0.00", which is noise on a document rather than
 * information on it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DiscountLine
{
    public function __construct(
        /** What the line says it is, in the line's own description field. */
        public string $description,
        public Amount $quantity,
        public Amount $price,
    ) {
    }
}

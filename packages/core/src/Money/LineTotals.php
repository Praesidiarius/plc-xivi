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
 * "These rows are what I sell, and this is where the money goes" (XIV-19).
 *
 * The order module worked its own totals out in a class of its own (XIV-16), and
 * the invoice module wanted the same arithmetic — which §3 forbids it from
 * importing, and nobody should want it to duplicate. So the arithmetic moved
 * here and what is left is a declaration, the same move {@see
 * \Xivi\Core\Numbering\NumberFormat} made for document numbers.
 *
 * **It names fields rather than assuming them.** A module is free to call its
 * quantity `hours` and its total `betrag`, and a customer is free to rename the
 * labels afterwards (§6.1) — what this pins down is the *keys*, which is exactly
 * what a module owns and a customer does not.
 *
 * Everything about the arithmetic itself is in {@see DerivesTotals} and in
 * {@see Amount}, which is where the rounding rule lives. This is a list of names.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class LineTotals
{
    public function __construct(
        /** The collection holding the lines. */
        public string $collection,
        /** On a line: how many, at what each, and what that comes to. */
        public string $quantity,
        public string $unitPrice,
        public string $lineTotal,
        /** On the record: what it comes to before tax, and after. */
        public string $netTotal,
        public string $grossTotal,
        /**
         * The line's VAT rate, as a percentage. Null for a module that does not
         * do tax at all, which then has a gross total equal to its net one — a
         * real case rather than a placeholder, and the shape a customer who is
         * not registered for VAT is in.
         */
        public ?string $taxRate = null,
        /** Where the VAT adds up to. Null with no rate to add up. */
        public ?string $taxTotal = null,
        /**
         * The collection holding one row per rate (§5.9), and the three fields
         * of such a row. Null for a document that states one figure and does not
         * break it down.
         */
        public ?string $taxes = null,
        public string $rate = 'rate',
        public string $taxableNet = 'net',
        public string $taxAmount = 'amount',
        /**
         * Which kind of row restates the block above it rather than charging for
         * anything (§5.9). Null for a collection whose rows are all lines.
         */
        public ?string $subtotalKind = null,
    ) {
    }
}

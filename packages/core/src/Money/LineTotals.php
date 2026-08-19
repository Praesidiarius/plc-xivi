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
        /**
         * Which kind of row the engine generates when something discounts this
         * document (XIV-104), and the line field such a row says itself in.
         *
         * Null for a module nothing can discount, which is every module that has
         * not asked — an invoice today, and a customer's order module before they
         * had vouchers. It is what {@see DerivesTotals} asks a
         * {@see DocumentDiscounts} about, and it is also what tells the engine
         * which of the rows it has been handed are **its own**: a row of this
         * kind is worked out on every save from whatever granted the discount,
         * never typed, and the metadata editor does not offer it as a kind
         * somebody can add (§5.5, {@see \Xivi\Core\Metadata\AvailableVariants}).
         *
         * `subtotalKind` above is the precedent and it is worth saying how far it
         * goes: a subtotal's *figure* is the engine's and the row is the
         * customer's — they add it, move it and delete it, and only the number in
         * it is computed. A discount row is the engine's **whole**, because it is
         * a fact about a voucher somebody redeemed rather than a heading somebody
         * wanted, and a customer who could delete it would have an order that
         * quietly disagreed with the use it consumed.
         */
        public ?string $discountKind = null,
        /**
         * The line's own text — what an article line is called, what a comment
         * says, and what a generated discount line has to be able to fill in.
         *
         * Null for a collection whose rows say nothing, in which case a discount
         * line is emitted without one; a module that can carry a discount and
         * whose lines have a required description will want it set, because a
         * generated row that leaves a required field empty is a row the next save
         * refuses.
         */
        public ?string $description = null,
        /**
         * The record's own field saying whether its prices already include VAT
         * (XIV-116), holding one of {@see VatMode}'s values.
         *
         * **On the record and not on a line**, which is the decision rather than
         * an implementation detail: a document with some lines quoted gross and
         * some quoted net is a document nobody can read, and no recipient could
         * check a column whose meaning changed halfway down it. Everything else
         * about money here is per line precisely because a *rate* genuinely
         * differs line by line; how to read a price does not.
         *
         * Null for a module that does not offer the choice, and — just as
         * importantly — for a customer who has deleted the field or has never
         * taken it from the upgrade offer (§6.1, §7.2.1). All three mean the same
         * thing to the arithmetic, and it is the thing every stored record
         * already means: prices exclude VAT.
         */
        public ?string $vatMode = null,
    ) {
    }
}

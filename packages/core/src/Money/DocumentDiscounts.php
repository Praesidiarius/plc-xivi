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

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * Something that knows a document is discounted, asked while its totals are
 * being worked out (XIV-104).
 *
 * The seam between a voucher and an order, and it is one method because §3 says
 * it has to be: the voucher package may not import the order package and the
 * order package may not import the voucher one, so the only place the two can
 * meet is here — where the arithmetic already lives.
 *
 * **Why this is a seam on the deriver and not a second deriver.** A discount
 * changes the VAT base, so the discount lines have to be in the grouping before
 * the VAT table is computed from it; and the amount of a *relative* discount is a
 * fact about what the lines came to, so it cannot be worked out before the lines
 * are summed. That is an ordering between two pieces of arithmetic, and
 * {@see \Xivi\Core\Record\ValueDeriver} is explicit that order between derivers
 * is unspecified — deliberately, because two modules arguing over one field is
 * not the engine's argument to settle. A second deriver would therefore have been
 * correct only half the time, and the half it was wrong in would store an order's
 * totals computed without its own discount. So there stays exactly one deriver
 * for a document's money, and what it does not know it asks.
 *
 * **Two modes, one seam** ([XIV-122]). A discount either comes off the document
 * as a whole — in which case there is no line for it to belong to and it gets one
 * of its own — or off a single line that is already there, in which case reducing
 * that line is the natural reading and adding a second one beside it would be a
 * document saying the same thing twice. Which of the two it is belongs to
 * whatever grants the discount, so it is stated in the answer ({@see Discount})
 * rather than asked for in a second method here. The engine's half is unchanged
 * by it: *how much comes off, and off what*.
 *
 * **It is asked about every document, and most sources will say nothing.** `null`
 * means *not mine*, which is both the invoice that has no voucher field and the
 * order in a tenant that never installed vouchers; see {@see Discount} for why
 * that is a different answer from a discount worth nothing.
 *
 * **A read, never a write.** {@see DerivesTotals} is `SafeToPreview`, so this is
 * called on every keystroke of a form somebody is still typing into (XIV-32) —
 * the figures have to follow the typing, and a voucher's code being entered is
 * exactly the moment somebody wants to see what it is worth. Consuming a use here
 * would consume one per keystroke. Redeeming belongs to whatever commits the
 * document, and for a voucher that is a subscriber on `RecordChanged`, inside the
 * same transaction (§5.19).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag(self::TAG)]
interface DocumentDiscounts
{
    public const string TAG = 'xivi.document_discounts';

    /**
     * What comes off this document, or null if this source has nothing to say
     * about it.
     *
     * **Still one question and one method** ([XIV-122]). A line voucher and an
     * order voucher are two answers rather than two questions: both are "what
     * comes off this document", and both are decided from the same record in the
     * same save. Asking twice would let a source answer one way about the header
     * and another way about the lines, with nothing anywhere reconciling the two —
     * and it would ask the ordering question this seam exists to avoid, since a
     * percentage off the whole document has to be a percentage of what is left
     * after the lines have been reduced.
     *
     * @param array<string, mixed>   $fields  the record's own values, as the save has
     *                                        them — the reference naming a voucher is
     *                                        one of these
     * @param Amount                 $lineSum what the priced lines came to before any
     *                                        discount, which is what a percentage is a
     *                                        percentage *of*. It carries whatever the
     *                                        line-total column carries, so it is a net
     *                                        on a net-priced document and a gross on a
     *                                        shelf-priced one (XIV-116) — and a tenth
     *                                        off is a tenth off either way
     * @param list<DiscountableLine> $lines   the lines that can be reduced, in the
     *                                        order the document has them and with what
     *                                        each charges. Generated discount rows are
     *                                        **not** among them: they are this seam's
     *                                        own output from the last save, and a
     *                                        source offered its own answer back would
     *                                        discount a discount
     */
    public function on(ModuleDefinition $module, array $fields, Amount $lineSum, array $lines): ?Discount;
}

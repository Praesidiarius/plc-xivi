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

use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Money\Amount;
use Xivi\Core\Money\Discount;
use Xivi\Core\Money\DiscountableLine;
use Xivi\Core\Money\DocumentDiscounts;
use Xivi\Core\Money\LineTotals;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\RecordRepository;

/**
 * What a document made from another one takes of that other one's discount
 * ([XIV-147]).
 *
 * ### The defect this exists to answer
 *
 * [XIV-104] made a discount **a line** on the order, and §5.12 copies lines onto
 * the invoice made from it, and what decides how much of a line a second invoice
 * may still bill is its **quantity**. A discount line has quantity 1. So the
 * first invoice took the whole voucher and every later one took none: a
 * thousand-franc order with a hundred-franc voucher billed in halves read
 * `500.00 − 100.00` and then `500.00 − 0.00`. The two came to 900.00, which is
 * why nobody noticed for a while and why this was a bug rather than a disaster,
 * and each of the two documents was one no human would have written.
 *
 * §5.25's line mode was worse in a quieter way. A reduction there is an amount on
 * the **line**, not a price per unit, and the seed copies the column across
 * whole — so an invoice for half a discounted line copied *all* of the reduction,
 * and two half invoices came to twice the voucher. That one does not add up, and
 * it is money nobody granted.
 *
 * ### The rule, which is one rule for both modes
 *
 * **Each document takes the share of what is left that matches what it bills,
 * and the document that closes the source out takes the balance.**
 *
 * Written as arithmetic, with the order's discount `D`, what its other invoices
 * already took `T`, what the order sells `S`, what they already billed `B`, and
 * what this one bills `L`:
 *
 *     share = (D − T) × L ÷ (S − B),   and (D − T) whenever L ≥ S − B
 *
 * Two properties follow from it and both are the ticket's conditions. The shares
 * **add back to `D` exactly**, because whatever the roundings before it did, the
 * invoice that finishes the order is handed the remainder rather than its own
 * division — which is XIV-116's *the stated figure is exact and the derived one
 * absorbs the rest*, applied one document further out than it was written. And no
 * invoice can take more than is left, because `D − T` is the ceiling of every
 * answer, so a discount cannot be over-applied by billing an order in eleven
 * pieces or by editing one of them afterwards.
 *
 * The same sentence runs per line for §5.25's mode, with that line's own
 * reduction for `D` and **quantities** rather than money for `L` and `S`: a
 * reduction belongs to one line, and how much of a line has been billed is a
 * count of it. That is the answer [XIV-147] asked for to the question of whether
 * the two modes agree — they do, and they had to, because a customer who was
 * given twenty francs off should be given twenty francs off however the shop
 * recorded it.
 *
 * ### Why the answer is worked out here rather than copied
 *
 * §5.12 is emphatic that an invoice is **copied, never read through**, and this
 * reads through. The distinction it is keeping is between *what was agreed* and
 * *what is left*: the same section already says that what is left is read rather
 * than stored, and this is the same reading one column further along. What is
 * copied is unchanged — the price, the rate, the description are the order's and
 * stay the order's, and editing the order afterwards moves none of them. What is
 * read is the one figure that cannot be a copy, because it is a fact about the
 * *set* of invoices rather than about any one of them.
 *
 * The alternative was to leave the discount stored on each invoice as it was
 * seeded, and it is what the code did. It cannot be made correct: the seeding
 * happens before anybody has said how much of the order this invoice is for.
 *
 * ### Two decisions inside that are not obvious
 *
 * **It reads the other invoices without asking whose they are.** {@see Seeder}
 * deliberately reads through the reader's own permissions and reports an order as
 * wholly uninvoiced to somebody who cannot see the invoices (§8.4), because it is
 * filling in a *form* and the safe direction for an offer is to offer less. This
 * is not an offer, it is a figure that gets stored, and the safe direction is the
 * other one: a discount worked out from the invoices the saver happens to be
 * allowed to see would let two people save the same document to two different
 * totals, and the second one would take a share of a voucher that was already
 * spent. Money on a document is a fact about the document.
 *
 * **The proportion is taken over what the lines *charge*, before any per-line
 * reduction.** That is the same figure {@see DocumentDiscounts} hands this method
 * as `$lineSum`, so both sides of the division are the same measurement — and
 * measuring the two sides differently is the one way a formula like this
 * quietly stops adding up.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SeededDiscounts implements DocumentDiscounts
{
    public function __construct(
        private ModuleRegistry $modules,
        private MetadataRepository $metadata,
        private RecordRepository $records,
    ) {
    }

    public function on(ModuleDefinition $module, ?int $document, array $fields, Amount $lineSum, array $lines): ?Discount
    {
        $blueprint = $this->modules->has($module->getKey()) ? $this->modules->get($module->getKey()) : null;
        $seed = $blueprint?->seed;
        $mine = $blueprint?->lineTotals;

        // **Not mine**, and three different ways of not being mine: a module
        // nothing is made from, one made from something that has no lines to
        // carry a discount, and one with no money on it at all. An order is the
        // first of the three, which is what keeps this class and the voucher's
        // own source from ever claiming the same document.
        if ($seed?->rows === null || $mine === null || !$this->modules->has($seed->from)) {
            return null;
        }

        $granted = $this->modules->get($seed->from)->lineTotals;

        // The source has no way of being discounted, so nothing made from it has
        // a share of anything. Still `null` rather than `none()`: saying "worth
        // nothing today" would take away a discount row that arrived some other
        // way, and this class has no standing to do that.
        if ($granted === null || ($granted->discountKind === null && $granted->lineDiscount === null)) {
            return null;
        }

        $source = $this->metadata->find($seed->from);
        $sourceLines = $source?->getCollection($granted->collection);

        if ($source === null || $sourceLines === null) {
            return null;
        }

        $id = $fields[$seed->link] ?? null;

        if (!is_numeric($id)) {
            // **Mine, and made from nothing.** A bill somebody typed from
            // scratch rather than from an order — which the invoice module
            // supports and demo data produces. It is discounted by nothing, and
            // saying so is what stops a discount row surviving on a document
            // whose order was taken off it.
            return Discount::none();
        }

        $record = $this->records->find($source, (int) $id);

        if ($record === null) {
            // **Named, and unreadable** — deleted, or in a module this
            // installation no longer has. The same three-state distinction
            // {@see Discount} spends its length on: this is not "worth nothing",
            // it is "I cannot tell", and the rows that are on the document stay
            // exactly as they were copied. A deleted order must not silently
            // restate the drafts billed from it.
            return null;
        }

        $agreed = $this->agreedOn($sourceLines, $granted, (int) $record->id);
        $billed = $this->billedElsewhere($module, $mine, $seed, (int) $id, $document);

        $off = self::shareOf(
            $agreed['off']->minus($billed['off']),
            $lineSum,
            $agreed['sold']->minus($billed['sold']),
        );

        return new Discount(
            off: $off->isPositive() ? $off : null,
            label: $agreed['label'],
            perLine: self::perLine($seed->rows, $mine, $lines, $agreed, $billed),
        );
    }

    /**
     * What the source document says was granted on it: the discount off the
     * document, what its lines charge, what came off each line, and how much of
     * each line there is.
     *
     * The two per-line arrays are keyed by the source row's **id**, which is what
     * a seeded row records about where it came from ({@see SeedRows::$source}) and
     * therefore the only key both documents can agree on.
     *
     * The label comes along because a generated discount line has to say what it
     * is, and on an invoice the honest answer is the sentence the order used —
     * the voucher's own code, which the order stored for exactly this reason
     * (§5.24) and which is still the word the customer holding it recognises.
     *
     * @return array{off: Amount, sold: Amount, label: string, perLine: array<int, Amount>, quantity: array<int, Amount>}
     */
    private function agreedOn(CollectionDefinition $lines, LineTotals $totals, int $recordId): array
    {
        $kindField = $lines->getVariantField();

        $off = Amount::zero();
        $sold = Amount::zero();
        $label = '';
        $perLine = [];
        $quantity = [];

        foreach ($this->records->findChildren($lines, $recordId) as $row) {
            $charge = self::chargeOf($row->data, $totals);

            if ($charge === null) {
                // A comment, a subtotal: nothing charged, nothing to share.
                continue;
            }

            if ($kindField !== null && $totals->discountKind !== null
                && $row->get($kindField) === $totals->discountKind) {
                // A generated discount row, whose price is negative because that
                // is how a discount is a line. What is granted is its size.
                $off = $off->minus($charge);

                if ($label === '' && $totals->description !== null) {
                    $label = (string) ($row->get($totals->description) ?? '');
                }

                continue;
            }

            $sold = $sold->plus($charge);

            $reduction = $totals->lineDiscount === null ? null : Amount::of($row->get($totals->lineDiscount));
            $count = Amount::of($row->get($totals->quantity));

            if ($reduction !== null && $reduction->isPositive() && $count !== null) {
                $perLine[(int) $row->id] = $reduction;
                $quantity[(int) $row->id] = $count;
            }
        }

        return ['off' => $off, 'sold' => $sold, 'label' => $label, 'perLine' => $perLine, 'quantity' => $quantity];
    }

    /**
     * What the source's **other** documents of this module have already taken,
     * in the same four shapes.
     *
     * `$document` is left out of it, and that is the whole reason the seam knows
     * which record it is asking about. Re-saving an invoice that is already
     * stored would otherwise meet its own last answer here, subtract it from what
     * is left to share, and hand the document a smaller discount every time
     * somebody pressed save — down to nothing once the order was fully billed,
     * where what is left is zero and what is open is zero with it.
     *
     * @return array{off: Amount, sold: Amount, perLine: array<int, Amount>, quantity: array<int, Amount>}
     */
    private function billedElsewhere(
        ModuleDefinition $module,
        LineTotals $totals,
        Seed $seed,
        int $sourceId,
        ?int $document,
    ): array {
        $off = Amount::zero();
        $sold = Amount::zero();
        $perLine = [];
        $quantity = [];

        $lines = $module->getCollection($totals->collection);
        $source = $seed->rows?->source;

        if ($lines === null || $source === null) {
            return ['off' => $off, 'sold' => $sold, 'perLine' => $perLine, 'quantity' => $quantity];
        }

        $kindField = $lines->getVariantField();

        // Unrestricted on purpose; the class comment says why at length. In
        // short: this figure is stored on a document, and a stored figure that
        // depended on who pressed save would be a different total for every
        // reader who had one.
        $made = $this->records->findBy(
            $module,
            new RecordQuery([new Filter($seed->link, Operator::Equals, $sourceId)]),
            RecordAccess::unrestricted(),
        );

        foreach ($made as $other) {
            if ((int) $other->id === $document) {
                continue;
            }

            foreach ($this->records->findChildren($lines, (int) $other->id) as $row) {
                $charge = self::chargeOf($row->data, $totals);

                if ($charge === null) {
                    continue;
                }

                if ($kindField !== null && $totals->discountKind !== null
                    && $row->get($kindField) === $totals->discountKind) {
                    $off = $off->minus($charge);

                    continue;
                }

                $sold = $sold->plus($charge);

                $from = $row->get($source);

                if (!is_numeric($from)) {
                    // A line somebody added to the bill by hand. It bills none of
                    // the order, so it has drawn nothing down.
                    continue;
                }

                $reduction = $totals->lineDiscount === null ? null : Amount::of($row->get($totals->lineDiscount));
                $count = Amount::of($row->get($totals->quantity));

                if ($reduction !== null) {
                    $perLine[(int) $from] = ($perLine[(int) $from] ?? Amount::zero())->plus($reduction);
                }

                if ($count !== null) {
                    $quantity[(int) $from] = ($quantity[(int) $from] ?? Amount::zero())->plus($count);
                }
            }
        }

        return ['off' => $off, 'sold' => $sold, 'perLine' => $perLine, 'quantity' => $quantity];
    }

    /**
     * What comes off each of this document's lines, keyed the way
     * {@see Discount::$perLine} is.
     *
     * **Counted rather than weighed.** A line's reduction belongs to that line, so
     * the proportion is over how much of the line has been billed and not over
     * what it came to — which also means the answer does not move when somebody
     * negotiates the price down on the second bill, and it should not: the twenty
     * francs off were twenty francs off ten of them, whatever the ten cost in the
     * end.
     *
     * A line that names no source row is one somebody typed onto the bill, and
     * nothing granted it anything. Returning no entry for it is not the same as
     * leaving it alone: {@see \Xivi\Core\Money\DerivesTotals} restates every
     * line's column from this answer, so a missing entry clears a figure somebody
     * forged into it, which is the protection §5.24 gives the generated row and
     * §5.25 gives this column.
     *
     * @param list<DiscountableLine>                                                                                     $lines
     * @param array{off: Amount, sold: Amount, label: string, perLine: array<int, Amount>, quantity: array<int, Amount>} $agreed
     * @param array{off: Amount, sold: Amount, perLine: array<int, Amount>, quantity: array<int, Amount>}                $billed
     *
     * @return array<int, Amount>
     */
    private static function perLine(SeedRows $rows, LineTotals $totals, array $lines, array $agreed, array $billed): array
    {
        $off = [];

        foreach ($lines as $line) {
            $from = $line->data[$rows->source] ?? null;

            if (!is_numeric($from) || !isset($agreed['perLine'][(int) $from])) {
                continue;
            }

            $source = (int) $from;
            $count = Amount::of($line->data[$totals->quantity] ?? null);

            if ($count === null) {
                continue;
            }

            $share = self::shareOf(
                $agreed['perLine'][$source]->minus($billed['perLine'][$source] ?? Amount::zero()),
                $count,
                $agreed['quantity'][$source]->minus($billed['quantity'][$source] ?? Amount::zero()),
            );

            if ($share->isPositive()) {
                $off[$line->index] = $share;
            }
        }

        return $off;
    }

    /**
     * One share of what is left, and the balance for whoever takes the rest.
     *
     * The three-line heart of this class. `$left` is what has not been given out
     * yet, `$whole` is how much of the source is still to be billed, and `$part`
     * is how much of it this document is billing.
     *
     * **The balance rather than a division when this document takes the rest**,
     * which is where every rounding that came before it goes. `$whole` at or below
     * `$part` is that case, and it covers three of them at once: the last invoice
     * of several, the only invoice of one, and an invoice that bills *more* than
     * the order had left — which is a thing somebody can do by editing a draft,
     * and which must hand over what is left rather than a share larger than it.
     *
     * Compared by subtraction because that is what {@see Amount} offers, and a
     * comparison method invented for one caller would be one more way of asking
     * the same question — the same reasoning `DerivesTotals` gives for its cap.
     */
    private static function shareOf(Amount $left, Amount $part, Amount $whole): Amount
    {
        if (!$left->isPositive()) {
            // Nothing left, or a source whose documents have somehow taken more
            // than was granted. Either way there is no more to give out.
            return Amount::zero();
        }

        if (!$whole->minus($part)->isPositive()) {
            return $left;
        }

        return $left->shareOf($part, $whole);
    }

    /**
     * What one row charges, or null when it charges nothing.
     *
     * The same two columns and the same rounding {@see DerivesTotals} uses, so
     * that what this reads off a stored document is the figure that document's own
     * arithmetic produced. Reading the stored line total instead would be a
     * different measurement on one side of the division: the line total already
     * has the row's reduction taken off it, and `$lineSum` on the other side does
     * not.
     *
     * @param array<string, mixed> $data
     */
    private static function chargeOf(array $data, LineTotals $totals): ?Amount
    {
        $quantity = Amount::of($data[$totals->quantity] ?? null);
        $price = Amount::of($data[$totals->unitPrice] ?? null);

        return $quantity === null || $price === null ? null : $quantity->times($price)->rounded();
    }
}

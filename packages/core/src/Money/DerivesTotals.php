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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Derivation;
use Xivi\Core\Record\SafeToPreview;
use Xivi\Core\Record\ValueDeriver;

/**
 * What a document with lines comes to, worked out while it is being saved
 * (XIV-16, generalised by XIV-19).
 *
 * This was the order module's own class until the invoice module wanted the same
 * arithmetic. Two modules needing identical sums is the engine's problem rather
 * than theirs — §3 forbids one package importing the other, and the alternative
 * was the same hundred lines twice, drifting apart the first time somebody fixed
 * a rounding bug in one of them.
 *
 * **A line contributes if it has a price, not if it is the right kind.** Every
 * line goes through the same loop; a comment line falls out of it because it has
 * no quantity and no unit price, which is a fact about the line rather than a
 * branch about its kind. So a fifth kind of line needs nothing here.
 *
 * The one thing kind *is* asked about is where a subtotal sits, because a
 * subtotal is defined by being one: it restates the priced lines since the
 * previous subtotal and then starts the count again. It adds **nothing** to the
 * document's own totals — it is a restatement of lines already counted, and
 * double-counting it is the single arithmetic mistake this file can make.
 *
 * **Rounding lives in {@see Amount}**, which is where the rule is written down
 * once for every module that will ever declare one of these.
 *
 * ## A price may already have the VAT in it (XIV-116)
 *
 * The document says which (`LineTotals::$vatMode`, {@see VatMode}), and this is
 * still the only class that computes any of it. **Inclusive is not a second
 * deriver**: it is the same loop with the same rounding rule, run in the other
 * direction. Two derivers would be two places to fix a rounding bug, and §5.9
 * spent a paragraph on why there is exactly one.
 *
 * What is identical in both modes, and worth listing because it is most of the
 * file: a line total is quantity times price rounded to two places; a comment
 * line contributes nothing; a subtotal restates the block above it; the VAT table
 * has one row per rate; a rate of nothing gets no row. The `unit_price` column
 * and the `line_total` column carry gross figures in inclusive mode and net ones
 * in exclusive mode, and everything above is true of both.
 *
 * What differs is three lines at the end:
 *
 * - **Exclusive.** The lines sum to the **net** total. Tax per rate is
 *   `net × rate`, rounded once per rate. Gross is net plus tax.
 * - **Inclusive.** The lines sum to the **gross** total. Net per rate is
 *   `gross ÷ (1 + rate)`, rounded once per rate; tax per rate is **the
 *   remainder**, `gross − net`. Net is gross minus the tax.
 *
 * **The gross the customer typed is the gross that prints**, and the second
 * bullet is entirely in service of that. Deriving a net and then re-deriving a
 * gross from it is the mistake this ticket exists to remove: 19.95 at 8.1% has a
 * net of 18.46, and 18.46 plus 8.1% of itself is 19.96 — a rappen above the price
 * on the shelf, on the customer's own document, with nobody able to explain why.
 * Taking the tax as what is left of the gross makes that arithmetically
 * impossible rather than usually fine.
 *
 * **There is no remainder to place across rates.** VAT is grouped per rate before
 * it is rounded (§5.9), so each rate's gross is split into a net and a tax that
 * add back to exactly that rate's gross, and the document's totals are sums of
 * exact splits. A document at 8.1% and 2.6% therefore needs no rule about which
 * rate absorbs a leftover rappen, because neither of them ever produces one. The
 * remainder that does exist is *within* a rate, and it lands on the tax — the
 * derived figure — never on the gross, which is the figure somebody typed.
 * [XIV-104] decided the same question for discounts and agreed with it: **the
 * typed figure is exact and the derived figure absorbs what is left over.**
 *
 * ## Something outside the document may take money off it (XIV-104)
 *
 * A voucher, today. The document names one and the amount comes off before VAT,
 * which means it has to be *inside* the grouping below rather than subtracted
 * from the total afterwards — a discount outside the VAT table is a document
 * whose tax was computed on nets nobody was charged.
 *
 * **A discount is one or more lines**, generated here and belonging to the engine
 * (`LineTotals::$discountKind`). One per rate, because a line has to carry a rate
 * to join the grouping and no single rate is right on a document with two; each
 * takes its share pro rata and **the last one takes the balance**, which is where
 * a rappen that will not divide lands. See {@see self::discountRows()}, which is
 * where that is spelled out, and §5.24 for the argument.
 *
 * **How much comes off is not decided here.** {@see DocumentDiscounts} is asked,
 * and its answer is an amount and some lines — never a rate, never a share and
 * never a row of its own. That split is what keeps the voucher module out of the
 * arithmetic and this class out of the promotions business, and it is why there
 * is still exactly one deriver for a document's money: the ordering between "what
 * did the lines come to", "what is the voucher worth" and "what is the tax" is
 * strict in both directions, and derivers have no order (§5.9).
 *
 * The rows of that kind that are already on the document are taken **out of the
 * sums** before the question is asked, and put back only if nobody claims them.
 * That is not tidiness: a relative voucher is a tenth of what the lines came to,
 * and counting last save's discount line in that would take a tenth off a total
 * that already had a tenth off it, once per save, for as long as somebody kept
 * editing the order.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DerivesTotals implements ValueDeriver, SafeToPreview
{
    /** @param iterable<DocumentDiscounts> $discounts */
    public function __construct(
        private ModuleRegistry $modules,
        #[AutowireIterator(DocumentDiscounts::TAG)]
        private iterable $discounts = [],
    ) {
    }

    public function supports(ModuleDefinition $module): bool
    {
        return $this->totalsOf($module) !== null;
    }

    public function derive(ModuleDefinition $module, Derivation $derivation): void
    {
        $totals = $this->totalsOf($module);

        // A save that says nothing about the lines is not a save that emptied
        // them — a lifecycle transition writes the header alone. Recomputing
        // from rows nobody sent would zero the totals of every order anybody
        // confirms.
        if ($totals === null || !\array_key_exists($totals->collection, $derivation->rows)) {
            return;
        }

        // Which field on a row says what kind it is (§5.5). Looked up once: a
        // subtotal is the only thing here that is decided by kind rather than by
        // whether there is a price.
        $kindField = $module->getCollection($totals->collection)?->getVariantField();

        // Whether the prices on this document already have the VAT in them
        // (XIV-116). Read off the record's own field rather than out of a
        // setting, because it is a fact about *this* document: §5.16 makes the
        // same argument about a due date, and §5.9 made it first about the
        // totals themselves. An installation that changes what it prices in must
        // not restate a document somebody has already been sent.
        //
        // A module with no such field, a customer who has not taken it from the
        // upgrade offer, and a record written before this existed all arrive here
        // as `Excluded` — see VatMode::of(), which is the one place that mapping
        // is written.
        $mode = VatMode::of($totals->vatMode === null ? null : ($derivation->fields[$totals->vatMode] ?? null));

        $lines = [];
        // What the lines add up to. In exclusive mode that is the net total; in
        // inclusive mode it is the gross one. Deliberately not named for either:
        // it is the sum of the line-total column as printed, which is the one
        // sentence true in both modes.
        $lineSum = Amount::zero();
        /** @var array<string, Amount> $byRate keyed by the rate as it is stored */
        $byRate = [];
        // The priced lines since the last subtotal, which is what the next one
        // will say.
        $block = Amount::zero();
        // The discount lines a previous save wrote, by their index in `$lines`,
        // so that they can be taken back out of it if this save is going to
        // write them again (XIV-104).
        /** @var array<int, array{id: int|null, amount: Amount, rate: string}> $carried */
        $carried = [];

        // `['data' => …] + $line` below rather than a fresh pair, so whatever
        // else the caller put on a row survives being derived. The live form
        // (XIV-32) needs that: it hands rows in carrying the form index they
        // came from, and matching the figures back by *position* instead would
        // be an assumption that this loop emits one row per row forever —
        // silent when it broke, and wrong on a page showing somebody money.
        foreach ($derivation->rowsOf($totals->collection) as $line) {
            $data = $line['data'];
            $amount = self::amountOf($data, $totals);

            if ($amount === null) {
                // Nothing to charge for. A comment line, and also a subtotal —
                // which then says what the block above it came to and starts the
                // next one.
                $isSubtotal = $kindField !== null
                    && $totals->subtotalKind !== null
                    && ($data[$kindField] ?? null) === $totals->subtotalKind;

                $data[$totals->lineTotal] = $isSubtotal ? (string) $block : null;

                if ($isSubtotal) {
                    $block = Amount::zero();
                }

                $lines[] = ['data' => $data] + $line;

                continue;
            }

            $data[$totals->lineTotal] = (string) $amount;
            $lines[] = ['data' => $data] + $line;

            $block = $block->plus($amount);

            $rate = ($totals->taxRate === null ? null : Amount::of($data[$totals->taxRate] ?? null)) ?? Amount::zero();
            $key = (string) $rate;

            // **A row the engine wrote last time is kept out of the sum until it
            // is known whether it is going to be written again** (XIV-104). It
            // matters for exactly one figure and that figure is the whole
            // feature: a *relative* voucher is a tenth of what the lines came
            // to, and counting last save's discount line in that would take a
            // tenth off a total that already had a tenth off it, once per save,
            // for as long as somebody kept editing the order.
            if ($kindField !== null && $totals->discountKind !== null
                && ($data[$kindField] ?? null) === $totals->discountKind) {
                $carried[\count($lines) - 1] = ['id' => $line['id'] ?? null, 'amount' => $amount, 'rate' => $key];

                continue;
            }

            $lineSum = $lineSum->plus($amount);
            $byRate[$key] = ($byRate[$key] ?? Amount::zero())->plus($amount);
        }

        // **What discounts this document, if anything does** — and the three
        // answers are all different (§5.9, {@see Discount}). Nothing to say
        // leaves every row exactly where it was, which is what keeps the discount
        // lines an invoice copied down from its order (§5.12) from being taken
        // off it by a module that has never heard of vouchers. An answer, even an
        // empty one, makes the rows of that kind the engine's: the ones that were
        // there are dropped, and whatever is granted now is written in their
        // place, reusing their ids so that editing an order does not churn a row
        // per save.
        // **And only where the module said where such a line would go.** A
        // discount is a *row of a stated kind* (`discountKind`), so a module that
        // has not named one has nowhere to put a discount and is not asked
        // whether it has one — an invoice, today. Without this the answer to a
        // question nobody could act on would be written out as rows with no kind
        // on them, which is a shape §5.5 has no reading for.
        $discount = $totals->discountKind === null
            ? null
            : $this->discountOn($module, $derivation->fields, $lineSum);

        if ($discount === null) {
            foreach ($carried as $row) {
                $lineSum = $lineSum->plus($row['amount']);
                $byRate[$row['rate']] = ($byRate[$row['rate']] ?? Amount::zero())->plus($row['amount']);
            }
        } else {
            foreach (array_keys($carried) as $index) {
                unset($lines[$index]);
            }

            $lines = array_values($lines);

            foreach (self::discountRows($discount, $byRate, $totals, $kindField, array_column($carried, 'id')) as $row) {
                $amount = self::amountOf($row['data'], $totals) ?? Amount::zero();
                $row['data'][$totals->lineTotal] = (string) $amount;
                $lines[] = $row;

                $lineSum = $lineSum->plus($amount);
                $key = (string) (($totals->taxRate === null ? null : Amount::of($row['data'][$totals->taxRate] ?? null)) ?? Amount::zero());
                $byRate[$key] = ($byRate[$key] ?? Amount::zero())->plus($amount);
            }
        }

        $derivation->setRows($totals->collection, $lines);

        $taxes = self::taxesOf($byRate, $totals, $mode);

        if ($totals->taxes !== null) {
            $derivation->setRows($totals->taxes, $taxes);
        }

        $tax = Amount::zero();

        foreach ($taxes as $row) {
            $tax = $tax->plus(Amount::of($row['data'][$totals->taxAmount]) ?? Amount::zero());
        }

        // The one place the two modes part company, and it is deliberately this
        // small. Whichever of the two totals the lines already gave us is written
        // through untouched; the other is the one that moves by the tax.
        //
        // Inclusive mode subtracts rather than dividing again, and that is the
        // guarantee rather than an optimisation: the gross is exactly the sum of
        // the line-total column, so the figure the recipient can add up by hand
        // is the figure printed under it, and the net is whatever is left once
        // the per-rate tax has come out. Re-deriving the net from the rates a
        // second time here would reintroduce the rappen the per-rate split has
        // just spent its rounding budget avoiding.
        if ($mode === VatMode::Included) {
            $derivation->fields[$totals->grossTotal] = (string) $lineSum;
            $derivation->fields[$totals->netTotal] = (string) $lineSum->minus($tax);
        } else {
            $derivation->fields[$totals->netTotal] = (string) $lineSum;
            $derivation->fields[$totals->grossTotal] = (string) $lineSum->plus($tax);
        }

        if ($totals->taxTotal !== null) {
            $derivation->fields[$totals->taxTotal] = (string) $tax;
        }
    }

    /**
     * What a line charges, or null when it charges nothing.
     *
     * Rounded here, so that the lines printed on a document add up to the total
     * printed under them. Rounding once at the end instead would be a rappen
     * more accurate and visibly wrong to anybody checking the column.
     *
     * @param array<string, mixed> $data
     */
    private static function amountOf(array $data, LineTotals $totals): ?Amount
    {
        $quantity = Amount::of($data[$totals->quantity] ?? null);
        $price = Amount::of($data[$totals->unitPrice] ?? null);

        return $quantity === null || $price === null ? null : $quantity->times($price)->rounded();
    }

    /**
     * What takes something off this document, asked of everything that might
     * (XIV-104).
     *
     * **The first source that claims the document owns it.** Two of them wanting
     * the same lines is an argument between modules and the engine is not where
     * it gets settled — the same sentence {@see ValueDeriver} already writes
     * about two derivers wanting one field. Today there is one implementation and
     * one field it can be reached through; the loop is here so that [XIV-122]'s
     * line voucher can be a second one without this method changing.
     *
     * @param array<string, mixed> $fields
     */
    private function discountOn(ModuleDefinition $module, array $fields, Amount $lineSum): ?Discount
    {
        foreach ($this->discounts as $source) {
            $discount = $source->on($module, $fields, $lineSum);

            if ($discount !== null) {
                return $discount;
            }
        }

        return null;
    }

    /**
     * The lines a discount puts on the document, one per rate it comes off.
     *
     * ### Why it is one line per rate rather than one line
     *
     * A discount line has to carry a VAT rate or it falls out of the grouping
     * below entirely — and a discount outside the VAT table is a document whose
     * tax was computed on nets nobody was charged. On a single-rate order there
     * is one answer and therefore one line. On a document mixing 8.1% and 2.6%
     * there is no single right rate for one line to carry, so the discount
     * becomes **one line per rate**, each with that rate and its share of the
     * money. The distribution is then something the reader can add up, in the
     * column it belongs in, instead of a division hidden inside a deriver.
     *
     * ### Pro rata, and the last line absorbs the remainder
     *
     * Each rate's share is the discount in the proportion that rate's own lines
     * bear to all of them, rounded to two places ({@see Amount::shareOf()}).
     * Rounded shares do not have to add back: ten francs over three rates that
     * sold equal amounts is 3.33 three times, which is 9.99, and a voucher for
     * ten francs that took 9.99 off is a voucher that lied by a rappen.
     *
     * So **the last line is not computed, it is what is left**: the shares before
     * it are worked out and subtracted, and the final line takes the balance.
     * That is XIV-116's rule for VAT within a rate applied to a discount across
     * them — *the figure somebody stated is exact and the derived figure absorbs
     * what is left over* — with the voucher's own worth as the stated figure.
     * The lines come out **sorted by rate**, the same order the VAT table below
     * is in, so "the last one" is the highest rate on the document and a reader
     * checking the column meets the odd rappen where the same reader meets it in
     * every other table on the page.
     *
     * **Only rates that sold something positive take a share**, and the discount
     * is capped at what they came to. A rate whose own lines already net to
     * nothing has nothing to come off, and a voucher worth more than the order it
     * is used on must not turn into money owed back — the voucher module's
     * percentage stops at 100 for the same reason (§5.19).
     *
     * The ids of the rows this replaces come back in with it, matched by
     * position, so an order edited twice keeps the same discount rows rather than
     * deleting and re-inserting one on every save — which is the argument
     * {@see \Xivi\Core\Form\CollectionRowType} makes about a row's id, and the
     * same match by position the writer makes for a derived collection.
     *
     * @param array<string, Amount> $byRate what each rate sold, before the discount
     * @param list<int|null>        $ids    the rows this is replacing, in the order they were in
     *
     * @return list<array{id: int|null, data: array<string, mixed>}>
     */
    private static function discountRows(
        Discount $discount,
        array $byRate,
        LineTotals $totals,
        ?string $kindField,
        array $ids,
    ): array {
        $rows = [];
        $off = $discount->off;

        if ($off !== null && $off->isPositive()) {
            $bases = array_filter($byRate, static fn (Amount $sold): bool => $sold->isPositive());
            uksort($bases, static fn (string $a, string $b): int => (float) $a <=> (float) $b);

            $whole = Amount::zero();

            foreach ($bases as $sold) {
                $whole = $whole->plus($sold);
            }

            // Capped at what the document actually charges, so a fifty-franc
            // voucher on a twenty-franc order comes to twenty rather than to a
            // total somebody is owed. Compared by subtraction because that is
            // what `Amount` offers, and a comparison method invented for one
            // caller would be one more way to ask the same question.
            if ($off->minus($whole)->isPositive()) {
                $off = $whole;
            }

            $left = $off;
            $remaining = \count($bases);

            foreach ($bases as $rate => $sold) {
                --$remaining;
                // The last line is the balance rather than its own division. See
                // above: this is where the leftover rappen lands, on purpose and
                // in one place.
                $share = $remaining === 0 ? $left : $off->shareOf($sold, $whole);
                $left = $left->minus($share);

                if ($share->isZero()) {
                    continue;
                }

                $rows[] = self::discountRow(
                    $totals,
                    $kindField,
                    $discount->label,
                    Amount::of('1') ?? Amount::zero(),
                    Amount::zero()->minus($share),
                    (string) $rate,
                );
            }
        }

        // And whatever the discount hands over as a line in its own right — a
        // free article, which is a line at a quantity and a price of nothing
        // rather than a subtraction, because that is what receiving one is.
        foreach ($discount->lines as $line) {
            $rows[] = self::discountRow($totals, $kindField, $line->description, $line->quantity, $line->price, null);
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['id'] = $ids[$index] ?? null;
        }

        return $rows;
    }

    /**
     * One generated line, in the shape a row of the lines collection is stored
     * in.
     *
     * Every field it fills is one the module named in its own {@see LineTotals} —
     * this knows no key of its own, which is what lets an order module and an
     * invoice module with differently spelled columns share it.
     *
     * @return array{id: int|null, data: array<string, mixed>}
     */
    private static function discountRow(
        LineTotals $totals,
        ?string $kindField,
        string $description,
        Amount $quantity,
        Amount $price,
        ?string $rate,
    ): array {
        $data = [$totals->quantity => (string) $quantity, $totals->unitPrice => (string) $price];

        if ($kindField !== null && $totals->discountKind !== null) {
            $data[$kindField] = $totals->discountKind;
        }

        if ($totals->description !== null) {
            $data[$totals->description] = $description;
        }

        if ($totals->taxRate !== null && $rate !== null) {
            $data[$totals->taxRate] = $rate;
        }

        return ['id' => null, 'data' => $data];
    }

    /**
     * The VAT table: one row per rate, over the net that was sold at it.
     *
     * **Per rate, not per line.** A hundred lines each losing half a rappen to
     * rounding is fifty rappen of tax nobody owes; grouping first is both more
     * accurate and what the tax authority's own examples do.
     *
     * A rate of nothing gets no row. A customer who is not registered for VAT
     * has typed no rates anywhere and should see no VAT table at all, rather
     * than one line reading "0% of 1200.00 = 0.00". Zero-rated exports are a
     * real thing and want a *named* rate with a reason on the document, which is
     * a field this engine has not got yet — when it does, it will be a rate.
     *
     * Sorted by rate, so the table reads the same way on every document and a
     * row keeps its place between saves.
     *
     * **The table itself reads identically in both modes** (XIV-116), which is
     * worth saying out loud because it is not obvious and it is the property that
     * makes a gross-priced document intelligible to a tax inspector. A row is
     * always "this much net was sold at this rate and this much tax is owed on
     * it"; what the mode changes is which of the two the *lines* gave us and
     * which one this has to work out. Exclusive mode is handed the net and
     * multiplies; inclusive mode is handed the gross, divides for the net, and
     * takes the tax as what is left. Either way `net + amount` comes to exactly
     * what that rate's lines added up to, which is what makes the table sum to
     * the totals beside it in both directions.
     *
     * `$byRate` therefore carries **whatever the line-total column carries** —
     * net in one mode, gross in the other — which is why the parameter is not
     * named for either.
     *
     * @param array<string, Amount> $byRate
     *
     * @return list<array{id: int|null, data: array<string, mixed>}>
     */
    private static function taxesOf(array $byRate, LineTotals $totals, VatMode $mode): array
    {
        uksort($byRate, static fn (string $a, string $b): int => (float) $a <=> (float) $b);

        $rows = [];

        foreach ($byRate as $rate => $sold) {
            $amount = Amount::of($rate) ?? Amount::zero();

            if ($amount->isZero()) {
                continue;
            }

            if ($mode === VatMode::Included) {
                // The gross was typed, so it is fixed; the net is the rounded
                // quotient and the tax is the remainder. Subtracting rather than
                // computing the tax on the rounded net is the whole ticket: at
                // 19.95 and 8.1% the two answers differ, and only this one adds
                // back to 19.95.
                $net = $sold->withoutPercent($amount);
                $tax = $sold->minus($net);
            } else {
                $net = $sold;
                $tax = $net->percent($amount)->rounded();
            }

            $rows[] = ['id' => null, 'data' => [
                $totals->rate => (string) $rate,
                $totals->taxableNet => (string) $net,
                $totals->taxAmount => (string) $tax,
            ]];
        }

        return $rows;
    }

    private function totalsOf(ModuleDefinition $module): ?LineTotals
    {
        // By key, which is the customer's installed copy of this module. The
        // label may be anything they renamed it to (XIV-8) and the fields may
        // have grown (§6.1); the key is what stays put.
        $key = $module->getKey();

        return $this->modules->has($key) ? $this->modules->get($key)->lineTotals : null;
    }
}

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

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Derivation;
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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DerivesTotals implements ValueDeriver
{
    public function __construct(private ModuleRegistry $modules)
    {
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

        $lines = [];
        $net = Amount::zero();
        /** @var array<string, Amount> $byRate keyed by the rate as it is stored */
        $byRate = [];
        // The priced lines since the last subtotal, which is what the next one
        // will say.
        $block = Amount::zero();

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

                $lines[] = ['id' => $line['id'], 'data' => $data];

                continue;
            }

            $data[$totals->lineTotal] = (string) $amount;
            $lines[] = ['id' => $line['id'], 'data' => $data];

            $net = $net->plus($amount);
            $block = $block->plus($amount);

            $rate = ($totals->taxRate === null ? null : Amount::of($data[$totals->taxRate] ?? null)) ?? Amount::zero();
            $key = (string) $rate;
            $byRate[$key] = ($byRate[$key] ?? Amount::zero())->plus($amount);
        }

        $derivation->setRows($totals->collection, $lines);

        $taxes = self::taxesOf($byRate, $totals);

        if ($totals->taxes !== null) {
            $derivation->setRows($totals->taxes, $taxes);
        }

        $tax = Amount::zero();

        foreach ($taxes as $row) {
            $tax = $tax->plus(Amount::of($row['data'][$totals->taxAmount]) ?? Amount::zero());
        }

        $derivation->fields[$totals->netTotal] = (string) $net;
        $derivation->fields[$totals->grossTotal] = (string) $net->plus($tax);

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
     * @param array<string, Amount> $byRate
     *
     * @return list<array{id: int|null, data: array<string, mixed>}>
     */
    private static function taxesOf(array $byRate, LineTotals $totals): array
    {
        uksort($byRate, static fn (string $a, string $b): int => (float) $a <=> (float) $b);

        $rows = [];

        foreach ($byRate as $rate => $net) {
            $amount = Amount::of($rate) ?? Amount::zero();

            if ($amount->isZero()) {
                continue;
            }

            $rows[] = ['id' => null, 'data' => [
                $totals->rate => (string) $rate,
                $totals->taxableNet => (string) $net,
                $totals->taxAmount => (string) $net->percent($amount)->rounded(),
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

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

namespace Xivi\Voucher\Validity;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\NarrowsCandidates;
use Xivi\Core\Record\Record;
use Xivi\Voucher\VoucherModule;

/**
 * A voucher picker offers the vouchers that can be used today (XIV-175).
 *
 * XIV-172 gave each of an order's two pickers its own family and left the
 * calendar alone, so a voucher that expired last month was still in the list and
 * still refused by the save. This is the other half of the same complaint, and
 * the same answer: **a list should only offer what can actually be chosen.**
 *
 * The rule itself is {@see VoucherValidity}'s and is not restated here. What
 * this class is, is the place where that rule meets the engine's candidate path
 * ({@see NarrowsCandidates}), which is a seam rather than a rule because §3
 * forbids core from importing a module and forbids this module from knowing what
 * an order is.
 *
 * ### Two of the save's refusals are here, and two deliberately are not
 *
 * {@see \Xivi\Voucher\Redemption\RedeemsVouchers} refuses seven ways. XIV-172
 * took the two about *where* a voucher belongs. This takes the two about *when*.
 * The remaining ones were decided rather than skipped:
 *
 * **`exhausted` stays at the write, and that is a decision about what a list can
 * honestly promise.** Whether a use is left is not a property of a voucher, it
 * is a count read at a moment: {@see \Xivi\Voucher\Redemption\VoucherRedemptions}
 * decides it *inside* the statement that takes the use, with the limit in its
 * `WHERE`, precisely so that two checkouts a millisecond apart cannot both be
 * told yes (§5.19, XIV-103). A picker cannot join that race, it can only report
 * a number that was true when the page was drawn. Hiding a voucher that is
 * already used up would be helpful and would sometimes be wrong in the harmless
 * direction; showing one and thereby *promising* it is available is a lie the
 * list cannot keep, because the last use can go to somebody else between the
 * dropdown opening and the save. Since the second is what a filtered list
 * implies, and since the first cannot be had without the second, the honest
 * shape is to leave the count to the write that resolves it. Two people can
 * still both see `LAST-ONE`; one of them gets a sentence saying it has been used
 * up, which is the truth and is what the refusal is for.
 *
 * There is a second, quieter reason. Expiry is a comparison against a column and
 * costs the picker's query nothing; the tally lives in a different table
 * altogether, so narrowing on it would put a join, or a per-row read, on a list
 * that an order form draws once per line.
 *
 * **`wrong_article` is out of scope, and is not a fact about a voucher.** A line
 * voucher may name an article, and then it may only go on a line selling that
 * article. Whether that holds is a fact about the *line*: the same voucher is
 * offerable on one row of an order and not on the next, and it becomes wrong the
 * moment somebody changes the article on a row after choosing the voucher. This
 * seam is asked "which of your records may be offered" and is handed no line, no
 * row and no document, which is not an oversight in the seam but a description
 * of what the picker knows when it builds a list. Narrowing it would mean the
 * candidate path learning about the record being edited, which is a much larger
 * change than this ticket, and it would still be undone by the next edit to the
 * row. So a restricted voucher stays in the list and the write still refuses
 * with the sentence naming the article, which is where a rule that reads two
 * records has always had to live.
 *
 * ### And none of this weakens the write
 *
 * Every refusal in `RedeemsVouchers` is untouched. A picker is a convenience in
 * front of a guarantee: an import, a copy and anything else reaching the engine
 * draws no list at all, and the sentence about an expired voucher is what those
 * meet. What changed is who speaks first on the one path that has a picker.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class OffersUsableVouchers implements NarrowsCandidates
{
    public function __construct(private VoucherValidity $validity)
    {
    }

    public function moduleKey(): string
    {
        return VoucherModule::KEY;
    }

    /**
     * Out of date in either direction, as conditions a candidate must not match.
     *
     * **Each date is asked for only where the customer still has it** (§6.1). A
     * blueprint declares both, and an installed definition is the customer's:
     * somebody who deleted `valid_until` from their voucher module has a module
     * where nothing expires, and a condition on a column they do not have would
     * raise `UnsupportedQuery` on every voucher picker in their tenant. Offering
     * more than the strictest possible reading is the right failure here, and
     * the write still refuses whatever the dates that remain say.
     */
    public function unofferable(ModuleDefinition $module): array
    {
        $conditions = [];

        if ($module->getField(VoucherModule::VALID_UNTIL) !== null) {
            $conditions = [...$conditions, ...$this->validity->expiredFilters()];
        }

        if ($module->getField(VoucherModule::VALID_FROM) !== null) {
            $conditions = [...$conditions, ...$this->validity->notStartedFilters()];
        }

        return $conditions;
    }

    /**
     * The same question about a voucher already loaded.
     *
     * One call, because {@see VoucherValidity::isValidOn()} is the same rule the
     * two conditions above are, written for a record in hand. Neither takes a
     * day: they both read the process's, which is what keeps this and the
     * refusal at the write from ever disagreeing about which day it is.
     *
     * The module is not needed and not read. A voucher whose customer deleted
     * one of the date fields simply has nothing stored under it, and this
     * answers that a voucher with no dates is usable, which is exactly what the
     * loosened query above says about the same record.
     */
    public function offers(ModuleDefinition $module, Record $record): bool
    {
        return $this->validity->isValidOn($record);
    }
}

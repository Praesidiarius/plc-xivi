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

namespace Xivi\Voucher\Redemption;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Translation\TranslatableMessage;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Event\RecordChanged;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordRefused;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordTitle;
use Xivi\Voucher\Discount\VoucherReference;
use Xivi\Voucher\Validity\VoucherValidity;
use Xivi\Voucher\VoucherModule;

/**
 * A use of a voucher is taken when the document that uses it commits (XIV-104,
 * XIV-122).
 *
 * [XIV-103] built the counter and said this ticket would be its caller. This is
 * that caller, and everything interesting about it is *when* it runs and *what a
 * use is*.
 *
 * ### It is a subscriber, and that is what makes the timing right
 *
 * `RecordChanged` is dispatched **inside the writer's transaction** (§5.2), which
 * gives this three properties that no other seam in the engine gives at once:
 *
 * - **A use is taken when the order commits**, not when somebody types a code
 *   into a form. A code typed and then abandoned costs nothing, which it has to:
 *   the live form re-derives on every keystroke (XIV-32), and a redemption on
 *   that path would burn a voucher per character.
 * - **A save that fails takes nothing.** The redemption is a statement in the
 *   same transaction, so a rollback gives the use back with nobody having to
 *   remember to — which is the property [XIV-103] chose the shape of its
 *   statement for, and the reason it is not a queue or an afterwards.
 * - **A refusal takes the save down with it.** An exhausted voucher cannot be
 *   discovered any earlier than the statement that fails to increment the count,
 *   so the refusal has to be able to happen here, at the write — and
 *   {@see RecordRefused} is what turns it into a sentence on the field rather
 *   than a stack trace on somebody's screen.
 *
 * ### A use is a document, and a voucher on three lines is one use (XIV-122)
 *
 * The question [XIV-122] left open, and the answer is the invariant [XIV-104]
 * already wrote down rather than a new one: **the count is the number of
 * documents that carry the voucher.** An order with `HALF-OFF` on three of its
 * lines is one order carrying `HALF-OFF`, so it takes one use.
 *
 * "One use per line" was the alternative and it loses on what a limit *means*. A
 * customer told "this voucher may be used five times" reads that as five
 * customers, or five visits — a promotion whose budget is five. Under per-line
 * counting the same voucher is spent by one shopper who happened to buy five
 * things, and the shop's five-customer promotion ends at the first customer. The
 * limit would be counting keystrokes rather than deals.
 *
 * It also keeps one counter rather than two. Per-line counting needs a use to be
 * a *(voucher, line)* pair, which a `voucher_id` counter cannot express — so it
 * would have meant a second table with a second rule in it, which is exactly what
 * [XIV-122] was told not to build and would have been right to refuse anyway:
 * two counters that must agree are two counters that will not.
 *
 * ### Which means the diff is over a *set*, and that is the whole of the code
 *
 * [XIV-104] read one field's before-and-after because a voucher could only be in
 * one place. There are now many places — the header and every line — so what is
 * compared is the **set of vouchers the document carries**, before and after:
 *
 * | what happened                          | what it does           |
 * | -------------------------------------- | ---------------------- |
 * | the set gains one                      | takes a use of it      |
 * | the set loses one                      | gives that use back    |
 * | it swaps one for another               | both, in that order    |
 * | the document is deleted                | gives every one back   |
 * | a voucher moves from one line to another | nothing at all       |
 * | anything else about it moves           | nothing at all         |
 *
 * The fifth row is the one the set buys and a per-field diff could not: dragging
 * `HALF-OFF` from the first line to the second is two field changes and no change
 * to the document at all, and a naive reading would release and re-take — which
 * on a single-use voucher already at its limit would refuse a save that changed
 * nothing about how many times the voucher had been used.
 *
 * **The "before" set is reconstructed rather than read**, because by the time this
 * runs the rows are already written. It is the "after" set with the save's own
 * diff undone: a row that was added is taken out, a row that was removed is put
 * back with the voucher it had, and a row whose voucher changed gets the one it
 * came in with. Every one of those is in `RecordChanges` already (§5.2) — the
 * history entry and this class read the same facts, which is the property that
 * makes the reconstruction trustworthy rather than clever.
 *
 * ### Validity is checked here, once, and never again
 *
 * Whether the voucher is in date is asked at the moment a use is taken and at no
 * other moment. An order that was agreed while a promotion was running keeps its
 * discount after the promotion ends, because the discount is a fact about the
 * document (§5.9) and expiry is the calendar rather than an act (§5.19). The
 * alternative — re-checking on every save — would take the discount off a draft
 * somebody merely opened and re-saved the following week, which is the same
 * silent restatement that section exists to forbid.
 *
 * **There is deliberately no transition guard** (XIV-110). "This order's voucher
 * has since expired" would be a refusal to confirm an order the shop has already
 * agreed to, on the grounds that the shop took too long to confirm it — punishing
 * the wrong party for a delay that is not the customer's fault, over money the
 * shop has already decided to give away.
 *
 * ### Where a voucher may be applied is checked here too (XIV-122)
 *
 * The mode decides where a voucher belongs, and putting one in the other place is
 * refused with a sentence naming the fix. It has to be here and not in the
 * deriver, for the reason [XIV-104] states at length: a deriver cannot refuse, and
 * a rule checked on every keystroke of a form somebody is still typing into is a
 * rule that fires while they are halfway through choosing.
 *
 * It could not be a field definition or a validation constraint either, which is
 * the test [XIV-104] set for anything landing here: whether this voucher may go on
 * this line depends on the *voucher's* kind and on the *line's* article, which are
 * two records the constraint on either one cannot see.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: RecordChanged::class)]
final readonly class RedeemsVouchers
{
    public function __construct(
        private VoucherReference $vouchers,
        private VoucherRedemptions $redemptions,
        private VoucherValidity $validity,
        private RecordRepository $records,
    ) {
    }

    public function __invoke(RecordChanged $event): void
    {
        $header = $this->vouchers->fieldOn($event->module);
        $lines = $this->linesOf($event->module);

        if ($header === null && $lines === null) {
            return;
        }

        if ($event->action === RecordAction::Deleted) {
            // A deleted document stops carrying what it carried. Read off the
            // record and its rows rather than the changes, because a deletion has
            // no field diff — it is one fact and the entry says so (§5.2).
            //
            // **Behind the tombstone**, because by the time this runs the rows
            // are soft-deleted with their parent: the moment a document stops
            // carrying a voucher is the one moment its lines are not readable the
            // ordinary way, which is what `includeDeleted` on
            // {@see RecordRepository::findChildren()} exists for.
            foreach ($this->carriedNow($event, $header, $lines, deleted: true) as $taken) {
                $this->redemptions->release($taken);
            }

            return;
        }

        $after = $this->carriedNow($event, $header, $lines);
        $before = $this->carriedBefore($event, $header, $lines);

        // **Given back before taken**, and the order matters for exactly one
        // case: a document that swaps between two vouchers sharing a limit must
        // not be refused because its own release had not happened yet. Doing it
        // the other way round would be identical on most days and wrong on the
        // day it was not.
        foreach (array_diff($before, $after) as $released) {
            $this->redemptions->release($released);
        }

        foreach (array_diff($after, $before) as $voucherId) {
            $this->take($event, $voucherId, $header, $lines);
        }
    }

    /**
     * Every voucher this document carries now: the one on the header, and the one
     * on each of its lines.
     *
     * Read from the rows as they stand rather than from what was submitted,
     * because a save that touched only the header sends no rows at all and the
     * lines still carry what they carried.
     *
     * @return list<int>
     */
    private function carriedNow(
        RecordChanged $event,
        ?string $header,
        ?CollectionDefinition $lines,
        bool $deleted = false,
    ): array {
        $ids = [];

        if ($header !== null) {
            $ids[] = VoucherReference::idOf($event->record->data[$header] ?? null);
        }

        if ($lines !== null) {
            foreach ($this->rowsOf($event, $lines, $deleted) as $row) {
                $ids[] = $this->vouchers->idIn($lines, $row->data);
            }
        }

        return self::asSet($ids);
    }

    /**
     * The same set as it was before this save, undone from the diff.
     *
     * Three undoings and each is one entry in `RecordChanges` (§5.2):
     *
     * - the header's own `from`, when the field moved at all;
     * - a row that was **removed** put back with the voucher its summary says it
     *   had — the entry keeps the values precisely so it still reads after the row
     *   is gone, and that is what makes it readable here;
     * - a row that was **updated** given the voucher it came in with, and a row
     *   that was **added** taken out entirely.
     *
     * A row nothing happened to is in both sets unchanged, which is what makes
     * moving a voucher between two lines a no-op rather than a release and a
     * re-take.
     *
     * @return list<int>
     */
    private function carriedBefore(RecordChanged $event, ?string $header, ?CollectionDefinition $lines): array
    {
        $ids = [];

        if ($header !== null) {
            $change = $event->changes->fields[$header] ?? null;

            // No change means the header is carrying now what it carried then.
            $ids[] = VoucherReference::idOf(
                $change === null ? ($event->record->data[$header] ?? null) : ($change['from'] ?? null),
            );
        }

        if ($lines === null) {
            return self::asSet($ids);
        }

        $field = $this->vouchers->fieldOn($lines);
        $rows = [];

        foreach ($this->rowsOf($event, $lines) as $row) {
            $rows[(int) $row->id] = $this->vouchers->idIn($lines, $row->data);
        }

        foreach ($event->changes->collections[$lines->getKey()] ?? [] as $entry) {
            $id = $entry['child_id'] ?? null;
            $changed = (array) ($entry['changes'] ?? []);

            if (!\is_int($id) || $field === null) {
                continue;
            }

            $rows[$id] = match ($entry['action'] ?? null) {
                // Gone now, and carrying whatever the entry remembers of it.
                'removed' => VoucherReference::idOf(self::summarised($entry, $field)),
                // Its values moved; the voucher field may or may not be among
                // them, and when it is not the row's current value was also its
                // previous one.
                'updated' => \array_key_exists($field, $changed)
                    ? VoucherReference::idOf($changed[$field]['from'] ?? null)
                    : ($rows[$id] ?? null),
                // It did not exist, so it carried nothing.
                default => null,
            };
        }

        return self::asSet([...$ids, ...array_values($rows)]);
    }

    /**
     * The ids that are actually there, once, in a stable order.
     *
     * A set rather than a list because a voucher on three lines is one voucher on
     * one document, which is the invariant this whole class is arranged around.
     *
     * @param list<int|null> $ids
     *
     * @return list<int>
     */
    private static function asSet(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
    }

    /**
     * A removed row's value for one field, out of the summary the writer kept.
     *
     * @param array<string, mixed> $entry
     */
    private static function summarised(array $entry, string $field): mixed
    {
        $values = (array) ($entry['values'] ?? []);
        $value = $values[$field] ?? null;

        return \is_array($value) ? ($value['value'] ?? null) : null;
    }

    /**
     * The rows of the document's line collection as they stand.
     *
     * @return list<Record>
     */
    private function rowsOf(RecordChanged $event, CollectionDefinition $lines, bool $deleted = false): array
    {
        return $event->record->id === null
            ? []
            : $this->records->findChildren($lines, (int) $event->record->id, includeDeleted: $deleted);
    }

    /**
     * Which collection of this module has lines a voucher can go on, found the
     * way everything else here is found — by reading the customer's definitions
     * for a reference into vouchers (§3, XIV-13).
     */
    private function linesOf(ModuleDefinition $module): ?CollectionDefinition
    {
        foreach ($module->getCollections() as $collection) {
            if ($this->vouchers->fieldOn($collection) !== null) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * Take one use, or refuse the save with a sentence naming which way it went
     * wrong.
     *
     * Seven refusals and seven different sentences, because "the voucher is not
     * valid" is of no use to whoever is standing at the till: they can do
     * something about a code they mistyped, something else about one that has
     * been used up, nothing at all about one that starts next month except know
     * that it does — and something very simple about one they put on the order
     * when it belongs on a line.
     */
    private function take(RecordChanged $event, int $voucherId, ?string $header, ?CollectionDefinition $lines): void
    {
        $module = $event->module;
        $voucher = $this->vouchers->record($voucherId);
        // Which control the sentence should land on: the header's field when the
        // voucher is on the header, and otherwise the collection's, so a refusal
        // is drawn where the person can see what they typed.
        $onHeader = $header !== null
            && VoucherReference::idOf($event->record->data[$header] ?? null) === $voucherId;
        // **Null for a refusal about a line**, and that is deliberate rather than
        // a gap. `RecordSubmission::report()` puts a named refusal on that
        // control of the record form and an unnamed one on the form itself; a
        // line's voucher field is very often spelled the same as the header's —
        // an order calls both `voucher` — so naming it would land the sentence on
        // the header's picker, pointing at the one place the voucher is *not*.
        // The form itself is where somebody reads it and then looks down the
        // rows, which is where the problem is.
        $field = $onHeader ? $header : null;

        if ($voucher === null) {
            // Deleted since, or the module was uninstalled since. Both leave a
            // reference pointing at nothing (§7.6), and neither is something to
            // silently proceed past: the document would claim a discount nothing
            // can explain and consume a use of a counter nobody can read.
            throw $this->refuse('unavailable', ['%voucher%' => '#' . $voucherId], $field, sprintf(
                'Voucher #%d cannot be read: its record or its module is gone.',
                $voucherId,
            ));
        }

        $code = RecordTitle::of($this->vouchers->module() ?? $module, $voucher);
        $kind = $voucher->get(VoucherModule::KIND);

        // **Where it is, against where its mode says it belongs** (XIV-122).
        // Checked before validity on purpose: a voucher in the wrong place is a
        // mistake with an obvious fix, and telling somebody their voucher expired
        // when the real problem is that it is on the wrong row would send them
        // looking in the wrong place.
        if ($onHeader && !VoucherModule::isOrderKind($kind)) {
            throw $this->refuse('wrong_place_for_line', ['%voucher%' => $code], $field, sprintf(
                'Voucher "%s" applies to a single line and was put on the document.',
                $code,
            ));
        }

        if (!$onHeader && !VoucherModule::isLineKind($kind)) {
            throw $this->refuse('wrong_place_for_order', ['%voucher%' => $code], $field, sprintf(
                'Voucher "%s" applies to the whole document and was put on a line.',
                $code,
            ));
        }

        if (!$onHeader && $lines !== null) {
            $this->checkRestriction($event, $voucher, $voucherId, $code, $lines, $field);
        }

        if ($this->validity->hasExpired($voucher)) {
            throw $this->refuse('expired', ['%voucher%' => $code], $field, sprintf(
                'Voucher "%s" is past its last valid day.',
                $code,
            ));
        }

        if ($this->validity->hasNotStarted($voucher)) {
            throw $this->refuse('not_yet_valid', ['%voucher%' => $code], $field, sprintf(
                'Voucher "%s" is not valid yet.',
                $code,
            ));
        }

        $limit = $voucher->get(VoucherModule::MAX_REDEMPTIONS);

        try {
            // **The one decision in this class**, and it is a statement rather
            // than a comparison: whether there is a use left is decided inside
            // the write, with the limit in its `WHERE`, so two checkouts a
            // millisecond apart cannot both be told yes (§5.19).
            $this->redemptions->redeem($voucherId, is_numeric($limit) ? (int) $limit : null);
        } catch (VoucherExhausted $exhausted) {
            throw $this->refuse('exhausted', ['%voucher%' => $code], $field, $exhausted->getMessage());
        }
    }

    /**
     * A restricted voucher against the lines it was put on.
     *
     * **Every line carrying it has to satisfy the restriction**, not merely one of
     * them. A voucher for lines selling a particular article, dropped on two lines
     * where only one of them sells it, is half a mistake — and half a mistake
     * accepted silently is the version somebody discovers on the customer's copy
     * of the document.
     */
    private function checkRestriction(
        RecordChanged $event,
        Record $voucher,
        int $voucherId,
        string $code,
        CollectionDefinition $lines,
        ?string $field,
    ): void {
        $restriction = $this->vouchers->restrictionOf($voucher);

        if ($restriction === null) {
            return;
        }

        foreach ($this->rowsOf($event, $lines) as $row) {
            if ($this->vouchers->idIn($lines, $row->data) !== $voucherId) {
                continue;
            }

            if ($this->vouchers->articleIn($lines, $row->data) === $restriction) {
                continue;
            }

            $article = $this->vouchers->articleNamed($restriction) ?? ('#' . $restriction);

            throw $this->refuse(
                'wrong_article',
                ['%voucher%' => $code, '%article%' => $article],
                $field,
                sprintf('Voucher "%s" is restricted to lines for "%s".', $code, $article),
            );
        }
    }

    /**
     * One refusal, in both languages the engine needs it in.
     *
     * The sentences are this module's own catalogue, because they are about
     * vouchers and no other package could word them; the English half is for a
     * log, which is the split {@see \Xivi\Core\Record\CollectionTooLong} already
     * makes and the reason the engine needs no translator to refuse anything.
     *
     * @param array<string, string> $values
     */
    private function refuse(string $reason, array $values, ?string $field, string $message): RecordRefused
    {
        return RecordRefused::because(
            new TranslatableMessage('refusal.' . $reason, $values, VoucherModule::KEY),
            $message,
            $field,
        );
    }
}

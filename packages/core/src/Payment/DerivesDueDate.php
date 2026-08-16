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

namespace Xivi\Core\Payment;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\DateFieldType;
use Xivi\Core\Record\Derivation;
use Xivi\Core\Record\ValueDeriver;

/**
 * Writing down when a document falls due, once, as it goes out (XIV-67).
 *
 * ### The date is stored, and that is the whole ticket
 *
 * The tempting version computes it on read: the issue date plus whatever the
 * customer's terms say, worked out whenever somebody asks. It is wrong, and
 * quietly. **Terms change.** The day somebody edits a customer from thirty days
 * to fourteen, every invoice ever sent to them silently becomes due earlier —
 * some of them retroactively overdue, for a deadline that was never agreed. The
 * other direction is worse: loosening terms would rewrite the history of an
 * invoice that really was paid late, and tightening them would make one that was
 * paid on time look late in its own timeline.
 *
 * **What was agreed is a fact about that document**, and this is exactly the
 * argument §5.9 makes for the money model: totals are derived and then *stored*,
 * because a price list that changes must not restate an invoice somebody has
 * already been sent. A due date is that argument applied to a date, which is why
 * this is a {@see ValueDeriver} and not a method on a read model.
 *
 * ### Materialised on the way into the outstanding state
 *
 * A draft has no due date and does not need one: nobody owes anything for a
 * document that has not left the building, and a date on it would be a promise
 * made to nobody. The invoice module's own lifecycle already says where that
 * changes — "sent is the end of editing… the customer has the document now" — so
 * that is the moment, and {@see PaymentTerms::$outstanding} is how a module names
 * it.
 *
 * **Written only into an empty field**, which is what "agreed once and never
 * restated" reduces to — the same rule {@see \Xivi\Core\Numbering\AssignsNumbers}
 * follows for a document number, and for the same reason. Three things follow,
 * and they are the answers to what happens if somebody sends an invoice twice:
 *
 * - There is one way into `sent` and no way back to `draft` (§5.8), so this fires
 *   at most once per document in the ordinary course of things.
 * - If a build ever gained a second route in, the field is already filled and
 *   nothing moves. A document cannot silently acquire a later deadline by being
 *   sent again.
 * - Marking it paid or cancelling it leaves the state, so this does not fire
 *   there either — which is what keeps an invoice that predates this feature from
 *   quietly acquiring a due date, out of today's terms, on the day it is settled.
 *   Existing invoices are not backfilled, deliberately: guessing which terms were
 *   in force months ago and guessing wrong in the direction that says a paid
 *   invoice was late is the bad failure.
 *
 * ### Not previewable, on purpose
 *
 * Deliberately not {@see \Xivi\Core\Record\SafeToPreview}. The live form (XIV-32)
 * runs the cheap derivers at typing speed, and this one reads another record out
 * of the database; but the stronger reason is that there is nothing to show. The
 * form being typed into is a draft, a draft is never in the outstanding state, and
 * a preview of this would be a blank field redrawn on every keystroke.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DerivesDueDate implements ValueDeriver
{
    public function __construct(private PaymentTermsResolver $terms)
    {
    }

    public function supports(ModuleDefinition $module): bool
    {
        return $this->terms->declaredFor($module) !== null;
    }

    public function derive(ModuleDefinition $module, Derivation $derivation): void
    {
        $declared = $this->terms->declaredFor($module);

        if ($declared === null) {
            return;
        }

        $due = $derivation->fields[$declared->dueDate] ?? null;

        // Already agreed. See the class docblock: this is the whole of "and never
        // re-derived afterwards", and it is one condition rather than a rule about
        // which save this is.
        if ($due !== null && $due !== '') {
            return;
        }

        $state = $this->terms->stateFieldOf($module);

        if ($state === null || ($derivation->fields[$state] ?? null) !== $declared->outstanding) {
            return;
        }

        $issued = self::dateIn($derivation->fields[$declared->from] ?? null);
        $days = $this->terms->daysFor($module, $derivation->fields);

        // No issue date, or nobody anywhere has said how long the customer gets.
        // The field stays empty, and an empty due date is not overdue (XIV-67) —
        // so the failure is a column with nothing in it rather than a customer
        // chased over a deadline this installation invented for them.
        if ($issued === null || $days === null) {
            return;
        }

        $derivation->fields[$declared->dueDate] = $issued->modify(sprintf('+%d days', $days));
    }

    /**
     * The issue date, however this save happens to be carrying it.
     *
     * Two shapes reach a deriver and both are ordinary: the form hands back the
     * immutable date its field type builds, and a transition re-saves whatever
     * was read out of storage or typed by a fixture. Normalising here rather than
     * insisting on one of them keeps this working on the path that matters most —
     * the lifecycle transition, which writes the header alone and never goes near
     * a date widget.
     *
     * Anchored at midnight, because a due date is a calendar day: adding days to
     * a value carrying the current time would store one whose printed form
     * depends on what o'clock the send button was pressed.
     */
    private static function dateIn(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(DateFieldType::FORMAT);
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . DateFieldType::FORMAT, $value);

        return $date === false ? null : $date;
    }
}

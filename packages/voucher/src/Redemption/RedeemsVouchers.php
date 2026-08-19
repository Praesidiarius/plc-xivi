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
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Event\RecordChanged;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordRefused;
use Xivi\Core\Record\RecordTitle;
use Xivi\Voucher\Discount\VoucherReference;
use Xivi\Voucher\Validity\VoucherValidity;
use Xivi\Voucher\VoucherModule;

/**
 * A use of a voucher is taken when the document that uses it commits (XIV-104).
 *
 * [XIV-103] built the counter and said this ticket would be its caller. This is
 * that caller, and everything interesting about it is *when* it runs.
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
 * ### What a change of voucher means
 *
 * The count answers "how many documents carry this voucher", so the three verbs
 * follow from the diff rather than from a rule anybody has to remember:
 *
 * | what happened                | what it does           |
 * | ---------------------------- | ---------------------- |
 * | an order starts naming one   | takes a use            |
 * | it stops naming one          | gives that use back    |
 * | it swaps one for another     | both, in that order    |
 * | the order is deleted         | gives the use back     |
 * | anything else about it moves | nothing at all         |
 *
 * That last row is most of the traffic and is the reason this reads the *field
 * diff* rather than the record: confirming an order writes the header alone
 * (§5.9), editing a line rewrites its rows, and neither of them is a redemption.
 * A voucher whose own record was edited is not one either — redeeming is not a
 * change to the voucher (§5.19).
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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: RecordChanged::class)]
final readonly class RedeemsVouchers
{
    public function __construct(
        private VoucherReference $vouchers,
        private VoucherRedemptions $redemptions,
        private VoucherValidity $validity,
    ) {
    }

    public function __invoke(RecordChanged $event): void
    {
        $field = $this->vouchers->fieldOn($event->module);

        if ($field === null) {
            return;
        }

        if ($event->action === RecordAction::Deleted) {
            // A deleted document stops carrying what it carried. Read off the
            // record rather than the changes, because a deletion has no field
            // diff — it is one fact and the entry says so (§5.2).
            $taken = $this->vouchers->idIn($event->module, $event->record->data);

            if ($taken !== null) {
                $this->redemptions->release($taken);
            }

            return;
        }

        $change = $event->changes->fields[$field] ?? null;

        if ($change === null) {
            // The voucher did not move. Which is nearly every save there is: a
            // line edited, a status confirmed, a note typed.
            return;
        }

        $before = is_numeric($change['from']) ? (int) $change['from'] : null;
        $after = is_numeric($change['to']) ? (int) $change['to'] : null;

        // **Given back before taken**, and the order matters for exactly one
        // case: swapping an order from a voucher to itself cannot happen (the
        // diff would be empty), but swapping it between two vouchers that share a
        // limit is not a thing either, so what is really bought here is that a
        // release can never undo the redemption of the *same* statement's
        // voucher. Doing it the other way round would be identical today and
        // fragile the first time that stops being true.
        if ($before !== null) {
            $this->redemptions->release($before);
        }

        if ($after !== null) {
            $this->take($event->module, $after, $field);
        }
    }

    /**
     * Take one use, or refuse the save with a sentence naming which way it went
     * wrong.
     *
     * Four refusals and four different sentences, because "the voucher is not
     * valid" is of no use to whoever is standing at the till: they can do
     * something about a code they mistyped, something else about one that has
     * been used up, and nothing at all about one that starts next month except
     * know that it does.
     */
    private function take(ModuleDefinition $module, int $voucherId, string $field): void
    {
        $voucher = $this->vouchers->record($voucherId);

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
     * One refusal, in both languages the engine needs it in.
     *
     * The sentences are this module's own catalogue, because they are about
     * vouchers and no other package could word them; the English half is for a
     * log, which is the split {@see \Xivi\Core\Record\CollectionTooLong} already
     * makes and the reason the engine needs no translator to refuse anything.
     *
     * @param array<string, string> $values
     */
    private function refuse(string $reason, array $values, string $field, string $message): RecordRefused
    {
        return RecordRefused::because(
            new TranslatableMessage('refusal.' . $reason, $values, VoucherModule::KEY),
            $message,
            $field,
        );
    }
}

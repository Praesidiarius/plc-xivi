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

namespace Xivi\Core\Record;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A save a module refused from inside the transaction, with a sentence saying
 * why (XIV-104).
 *
 * ### This is half of §7.1, and only half
 *
 * {@see ValueDeriver} answers the question's other half at length: a module may
 * take part in a save, and what it may do there is *derive*. It may not cancel,
 * and there is deliberately nothing to cancel with. That stays true and nothing
 * here weakens it — **a deriver still cannot refuse.**
 *
 * What could always refuse, and did so as a stack trace, is a subscriber on
 * {@see \Xivi\Core\Event\RecordChanged}: the event is dispatched inside the
 * writer's transaction precisely so that a subscriber which fails takes the
 * change down with it. The engine has therefore had a veto in it since history
 * was written; what it has not had is a way for one to *say what it was*. So a
 * refusal thrown from there was a 500 page shown to somebody who typed a code
 * that had already been used.
 *
 * This is that missing half, and its shape is copied deliberately from
 * {@see DuplicateValue}, which solved the same problem for a unique index
 * (XIV-109): the refusal names the field it is about when it is about one, and
 * the form puts the sentence on that control and leaves everything the person
 * typed exactly where it was. A reader cannot tell the two apart, and should not
 * be able to.
 *
 * ### What may throw one, and what may not
 *
 * A refusal is for a rule that **can only be checked where the write happens**.
 * A voucher's last remaining use is the case this was written for: whether one is
 * left is decided by a statement with the limit inside it (§5.19), so no earlier
 * read can answer it and no validator can be given the answer to repeat. A rule
 * that could have been a field definition, a lifecycle transition or a validation
 * constraint belongs in one of those, where the person meets it before pressing
 * save rather than after.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordRefused extends \RuntimeException
{
    private function __construct(
        /**
         * The field the refusal is about, or null when it is about the record as
         * a whole.
         *
         * A key rather than a label, because the form looks the control up by it
         * — and a key that names no control on this form is not an error: the
         * sentence then goes on the form itself, where `form_errors()` draws it,
         * which is the same fallback {@see DuplicateValue} takes for a unique
         * field belonging to another variant.
         */
        public readonly ?string $fieldKey,
        private readonly TranslatableMessage $translatable,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param TranslatableMessage $reason  what a person reads, in their language
     * @param string              $message and the same thing in English, for a log —
     *                                     the engine has no translator and no business
     *                                     holding one, which is the split
     *                                     {@see CollectionTooLong} already makes
     */
    public static function because(TranslatableMessage $reason, string $message, ?string $fieldKey = null): self
    {
        return new self($fieldKey, $reason, $message);
    }

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }
}

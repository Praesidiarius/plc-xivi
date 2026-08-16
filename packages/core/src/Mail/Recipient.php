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

namespace Xivi\Core\Mail;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * What a module's {@see MailRecipient} declaration came to for one record
 * (XIV-39).
 *
 * The declaration is the shape; this is the answer. One value carrying both the
 * address and the reason there is none, because the caller asks one question —
 * "can this record be sent to, and if not, what do I tell them?" — and two
 * methods would be two chances to ask the first and forget the second. That is
 * the whole point of the ticket's rule that **a record with no address offers no
 * send and says why**: a modal that opens and cannot be completed is the failure
 * mode this shape exists to make impossible.
 *
 * It carries the labels as well as the address, and they are not decoration.
 * "This invoice names no Contact" is an actionable sentence; "no recipient" is
 * not, and the word *Contact* is the customer's own label for their own field
 * (§6.1) rather than anything this package could have written down.
 *
 * **Not an exception.** A record without an address is the ordinary state of
 * half a CRM — a contact who is a name and a phone number — and drawing a page
 * is not the place to be catching things.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Recipient
{
    private function __construct(
        /** The address to send to, or null when there is none to be had. */
        public ?string $address,
        public ?RecipientProblem $problem,
        /** The customer's label for the field the address was expected in. */
        public ?string $fieldLabel = null,
        /** Their label for the reference it was reached through, null for one hop of none. */
        public ?string $viaLabel = null,
        /** What was stored, for the one case where saying so is the explanation. */
        public ?string $value = null,
    ) {
    }

    public static function at(string $address, string $fieldLabel, ?string $viaLabel): self
    {
        return new self($address, null, $fieldLabel, $viaLabel);
    }

    public static function missing(
        RecipientProblem $problem,
        ?string $fieldLabel = null,
        ?string $viaLabel = null,
        ?string $value = null,
    ): self {
        return new self(null, $problem, $fieldLabel, $viaLabel, $value);
    }

    public function isResolved(): bool
    {
        return $this->address !== null;
    }

    /**
     * Whether this module sends mail at all.
     *
     * The record page asks this before it asks anything else, because a module
     * that declares no recipient should show nothing rather than an explanation
     * of a feature it does not have.
     */
    public function isOffered(): bool
    {
        return $this->problem !== RecipientProblem::NotDeclared;
    }

    /**
     * The sentence to put where the send button would have been.
     *
     * Two of the cases read differently depending on whether the address was
     * expected here or one hop away, and that difference is the useful half of
     * the sentence — "no email address" sends somebody looking at the wrong
     * record. So the key is chosen from the resolution rather than from the
     * problem alone, which is why this lives here and not on the enum.
     */
    public function reason(): ?TranslatableMessage
    {
        if ($this->problem === null || $this->problem === RecipientProblem::NotDeclared) {
            return null;
        }

        // NotDeclared has already returned above, which is why it is not an arm
        // here: a module that never claimed to send mail has nothing to explain.
        $key = match ($this->problem) {
            RecipientProblem::NoLink => 'no_link',
            RecipientProblem::LinkGone => 'link_gone',
            RecipientProblem::NotAnAddress => 'not_an_address',
            // The one case that reads differently from each side.
            RecipientProblem::NoAddress => $this->viaLabel === null ? 'no_address_here' : 'no_address_there',
        };

        return new TranslatableMessage(
            'mail.recipient.' . $key,
            [
                '%field%' => $this->fieldLabel ?? '',
                '%via%' => $this->viaLabel ?? '',
                '%value%' => $this->value ?? '',
            ],
            'xivi',
        );
    }
}

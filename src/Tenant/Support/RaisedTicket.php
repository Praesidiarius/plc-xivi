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

namespace App\Tenant\Support;

use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\SupportStatus;
use App\Tenant\Entity\SupportTicket;

/**
 * One of this customer's questions, with whatever the operator has said about
 * it, as their own screen draws it (XIV-123, §8.17).
 *
 * **Two databases in one readonly object**, which is the whole shape of the
 * feature: the question is the customer's own row and the answer is a
 * control-plane row, and this is the only type that holds both. Building it is
 * {@see SupportTickets}'s job; drawing it is the template's; and neither of them
 * has to remember which half came from where.
 *
 * ## Why "received" is an absence rather than a status
 *
 * {@see $status} is null until a collection has run. That is not a gap to be
 * defaulted to `Open`: *not yet collected* and *collected and nobody has looked
 * at it* are different facts, and the first is the one that explains why a
 * customer who pressed the button ninety seconds ago sees nothing on the
 * operator's side yet.
 *
 * §8.11 settled this shape for the usage figures — a customer nobody has
 * collected yet has **no row at all**, and absence says so exactly where a
 * nullable column would have needed a second column meaning "the nulls above are
 * real". Here it does the same job in the direction the customer is looking, and
 * it is what makes the collection delay honest rather than hidden: the page says
 * *"not received yet"* rather than pretending the operator has it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RaisedTicket
{
    public function __construct(
        public string $reference,
        public string $subject,
        public string $body,
        public \DateTimeImmutable $raisedAt,
        /** Who asked, as they were called then. This value never leaves the customer's database. */
        public string $raisedByLabel,
        /** Null until a collection has picked it up — see the class docblock. */
        public ?SupportStatus $status,
        public ?string $reply,
        public ?\DateTimeImmutable $repliedAt,
        public ?string $replyAuthorLabel,
    ) {
    }

    /**
     * @param SupportRequest|null $collected the control-plane copy, when a
     *                                       collection has been round since this
     *                                       was raised
     */
    public static function of(SupportTicket $ticket, ?SupportRequest $collected): self
    {
        return new self(
            $ticket->getReference(),
            $ticket->getSubject(),
            $ticket->getBody(),
            $ticket->getRaisedAt(),
            $ticket->getRaisedByLabel(),
            $collected?->getStatus(),
            $collected?->getReply(),
            $collected?->getRepliedAt(),
            $collected?->getReplyAuthorLabel(),
        );
    }

    /** Whether the operator has this at all yet. */
    public function isReceived(): bool
    {
        return $this->status !== null;
    }

    /** Whether somebody still owes an answer, from the customer's point of view. */
    public function isOutstanding(): bool
    {
        return $this->status === null || $this->status->isOutstanding();
    }
}

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

namespace App\Registry\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer's question as an operator meets it, and what the operator has done
 * about it (XIV-123, docs/architecture.md §8.17).
 *
 * The control-plane half of {@see \App\Tenant\Entity\SupportTicket}. The
 * customer's row is written in their own database because §4.4 leaves a
 * customer's request nowhere else to write; `tenant:support:collect` copies the
 * question over here; and **everything the operator adds is added here and
 * nowhere else**.
 *
 * ## Why this entity is `App\Registry\Entity` and not the administration
 * surface's — which is the whole design
 *
 * Because the *customer* reads it. `App\Deployment\RegistryGrants` derives the
 * tables a customer-facing instance may `SELECT` by walking the mapping for
 * `App\Registry\Entity\` and no other namespace, so the namespace is not a
 * filing decision, it is the grant ({@see Notice} says the same thing for
 * [XIV-120]). Filed in `Xivi\ControlPlane\Entity` beside {@see
 * \Xivi\ControlPlane\Entity\PurchaseIntent}, this row would be on the *withheld*
 * list and the customer's support page would meet SQLSTATE 42501.
 *
 * That difference from the purchase intent is the ticket's central decision
 * rather than an accident of where a file was put. A purchase request is
 * collected **for an operator to read**, so its copy is theirs alone and belongs
 * with their tables. A support ticket is collected so that an operator can
 * *answer*, and the answer has to reach the person who asked — so the copy is
 * read from both sides, and it lives where both sides can read it.
 *
 * **The consequence for a deploy is real and is stated in §4.4 and in
 * `CHANGELOG.md`**: this is a new registry table, so `deploy:registry-grants`
 * has to be run again on upgrade. An installation that skips it gets a
 * customer-facing instance whose role cannot read `support_request`, and the
 * support page fails immediately and loudly for that instance rather than
 * quietly for one customer. [XIV-120] made the same trade and wrote it down the
 * same way.
 *
 * ## Which columns are copies and which are ours
 *
 * {@see $subject}, {@see $body}, {@see $raisedAt} and {@see $reference} are the
 * customer's, copied verbatim by the collector and never edited here. {@see
 * $status}, {@see $reply}, {@see $repliedAt} and {@see $replyAuthorLabel} are
 * the operator's, written here and **never touched by the collector** — which is
 * the property everything else rests on. A collection that rewrote the whole row
 * would silently discard an answer whenever it overlapped with somebody typing
 * one, and the customer would be shown their own question back.
 *
 * ## What does not cross, and it is a decision rather than an omission
 *
 * **Who raised it.** The tenant-side row carries the person's id and the name
 * they had at the time; neither leaves that database. §8.11 drew the line at
 * *how much* rather than *what*, [XIV-102] held it for purchase requests, and it
 * costs nothing here because the reply is delivered inside the product rather
 * than by mail — an operator does not need an address to answer.
 *
 * ## One reply, revisable, and no thread
 *
 * {@see $reply} is a column rather than a table, and a customer cannot answer it
 * in place. A two-sided thread means every message from the customer crosses the
 * collector and every message from the operator needs attributing, which is a
 * conversation product rather than a column — and the honest first slice is that
 * a customer can ask, an operator can answer, and either of them can start
 * another ticket. §8.17 says so where a reader will meet it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_request')]
// Matched on the pair, never on the reference alone: a reference is produced
// inside a customer's database, and one customer must not be able to name
// another's row by producing the same string. See SupportTicket.
#[ORM\UniqueConstraint(name: 'uniq_support_request_reference', columns: ['tenant_id', 'reference'])]
#[ORM\Index(name: 'idx_support_request_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_support_request_raised', columns: ['raised_at'])]
class SupportRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The customer's words, copied. Refreshed by every collection and edited by nobody. */
    #[ORM\Column(length: 200)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    /** Their clock's answer for when it was asked, copied — which is what an operator sorts by. */
    #[ORM\Column(name: 'raised_at')]
    private \DateTimeImmutable $raisedAt;

    /** When the run that produced the four values above ran. Every one of them is relative to it. */
    #[ORM\Column(name: 'collected_at')]
    private \DateTimeImmutable $collectedAt;

    /**
     * Where the operator has got to.
     *
     * Written here, read by both sides, and **the collector never sets it after
     * the row is created** — see the class docblock. `Open` on arrival, because
     * a ticket nobody has looked at yet is exactly that.
     */
    #[ORM\Column(length: 32, enumType: SupportStatus::class)]
    private SupportStatus $status = SupportStatus::Open;

    /** The operator's answer, in plain text, or null while there is not one. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reply = null;

    #[ORM\Column(name: 'replied_at', nullable: true)]
    private ?\DateTimeImmutable $repliedAt = null;

    /**
     * Who answered, as they were called at the time.
     *
     * A copy rather than a foreign key to `operator`, for {@see Notice}'s reason
     * and it is the stronger form of it: the reader this column exists for is a
     * customer, and §4.4 gives their instance no access to that table at all, so
     * a join would be unreadable by the only party that needs the value.
     */
    #[ORM\Column(name: 'reply_author_label', type: Types::TEXT, nullable: true)]
    private ?string $replyAuthorLabel = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        /** The customer's own correlation key — 128 random bits, generated in their database. */
        #[ORM\Column(length: 32)]
        private string $reference,
    ) {
        // Overwritten by the first record() call, and set here so the object is
        // never in a state where it claims a collection time it has not got —
        // `PurchaseIntent` and `TenantUsage` take the same care for the same
        // reason.
        $this->collectedAt = new \DateTimeImmutable();
        $this->raisedAt = $this->collectedAt;
    }

    /**
     * What the last collection found, written in one call.
     *
     * One method rather than three setters, because these values are only ever
     * true together: they come out of one switch into one customer's database at
     * one moment. **Nothing here touches the status or the reply**, which is what
     * makes it safe to run the collector while somebody is typing an answer.
     */
    public function record(string $subject, string $body, \DateTimeImmutable $raisedAt): void
    {
        $this->subject = $subject;
        $this->body = $body;
        $this->raisedAt = $raisedAt;
        $this->collectedAt = new \DateTimeImmutable();
    }

    /**
     * Moves it, which is the one control on the operator's screen.
     *
     * No transition rules and no refusals: an operator reopening something they
     * closed by mistake is an ordinary Tuesday, and a lifecycle (§5.8) here would
     * be modelling a process nobody has described. The screen offers the three
     * states and any of them may follow any other.
     */
    public function moveTo(SupportStatus $status): void
    {
        $this->status = $status;
    }

    /**
     * Answers it, or rewrites the answer.
     *
     * Rewriting rather than appending, and {@see $repliedAt} moves with it: a
     * second version of an answer is the answer, and the moment that matters to
     * the person reading it is when the words in front of them were written.
     * There is no history of previous replies, which is the honest shape of one
     * column — §8.17 names it among what this does not do.
     */
    public function replyWith(string $reply, string $authorLabel, ?\DateTimeImmutable $now = null): void
    {
        $this->reply = $reply;
        $this->replyAuthorLabel = $authorLabel;
        $this->repliedAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getRaisedAt(): \DateTimeImmutable
    {
        return $this->raisedAt;
    }

    public function getCollectedAt(): \DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function getStatus(): SupportStatus
    {
        return $this->status;
    }

    public function getReply(): ?string
    {
        return $this->reply;
    }

    public function getRepliedAt(): ?\DateTimeImmutable
    {
        return $this->repliedAt;
    }

    public function getReplyAuthorLabel(): ?string
    {
        return $this->replyAuthorLabel;
    }
}

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

namespace App\Tenant\Entity;

use App\Tenant\Repository\SupportTicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody in this installation has asked the people who run it a question
 * (XIV-123, docs/architecture.md §8.17).
 *
 * Until this existed, a customer whose invoice module was behaving oddly — or
 * who wanted a module they could not see in the store — had **whatever email
 * address they happened to be given when they signed up**. [XIV-120] gave the
 * operator a way to talk *to* customers; this is the return path, and the two
 * are one feature seen from both ends: an announcement is one-to-many,
 * scheduled and about the installation, and a ticket is one-to-one,
 * unscheduled and about a problem.
 *
 * ## Why this table is in the customer's own database
 *
 * For {@see ModulePurchaseIntent}'s reason, word for word, because it is the
 * same direction. **A ticket is a write made by a customer's own request**, and
 * §4.4 gives the customer-facing instance's database role `SELECT` on the
 * registry tables and no write privilege anywhere in the control-plane
 * database, on any table, present or future. A feature whose first requirement
 * is a write from a customer's request therefore has exactly one database
 * available to it — theirs — and an operator sees it because
 * `tenant:support:collect` copies it across.
 *
 * The shape that removes the collector is the tempting one, and it was rejected
 * there and is rejected here: **an HTTP call to the control plane** would hand
 * the public image a credential that writes the control-plane database,
 * re-obtaining over the network precisely the privilege PostgreSQL refuses it.
 * §8.15 has the long version and §8.17 has why the delay it costs is acceptable
 * in this direction.
 *
 * ## What is deliberately *not* on this row, and it is the interesting half
 *
 * **No status and no reply.** Both belong to the operator, both live on the
 * control-plane copy ({@see \App\Registry\Entity\SupportRequest}), and the
 * customer reads them back out of the registry — which is exactly what §4.4's
 * grant has permitted since it was written. So the *return* leg needs no
 * collector and has no interval: an operator who answers at 14:03 has answered
 * on the customer's screen at 14:03. Only the outbound leg waits for a
 * collection, and it is the leg where nobody is watching a screen.
 *
 * Keeping a copy of the status here as well would be [XIV-98]'s mistake in a new
 * place: two rows in two databases holding one fact, free to disagree, with the
 * one an operator is looking at not being the one the customer is looking at.
 * And it would need the collector to write *into* every customer's database,
 * which nothing does today and which would make a background job the thing that
 * changes what a customer sees.
 *
 * ## The reference, and why it is not the id
 *
 * {@see $reference} is 128 random bits, generated here, and it is what the
 * collected copy is matched on. The primary key would have been the obvious
 * choice and is wrong for a reason this project has already met: ids are a
 * sequence per database, so a customer whose database is rebuilt — `tenant:reset`
 * does exactly that, and §4.1's rebuild is a supported operation — starts again
 * at 1, and the next collection would find "ticket 1" and overwrite the row
 * holding an operator's answer to somebody else's question. A random reference
 * cannot collide with itself across a rebuild.
 *
 * The collector matches on the pair `(tenant, reference)` rather than on the
 * reference alone, which is the second half of the same care: a reference is a
 * value produced inside a *customer's* database, and no customer should be able
 * to name a row belonging to another by producing the same string.
 *
 * ## Who raised it, copied rather than joined
 *
 * {@see $raisedById} and {@see $raisedByLabel} are {@see FollowUp}'s two-column
 * pattern for its reason: somebody who leaves the company should not take the
 * record of a question with them, and the name on a ticket is the name it was
 * raised under.
 *
 * **Neither value ever leaves this database**, which is [XIV-102]'s line held in
 * the place where it is most tempting to cross — an operator would obviously
 * like to know whom to write back to. They do not need to, because the answer is
 * delivered *in the product*: it lands on the collected row and the customer
 * reads it on the same screen they raised the ticket on. §8.11 drew the line at
 * *how much* rather than *what*, and a customer's own staff are on the far side
 * of it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: SupportTicketRepository::class)]
#[ORM\Table(name: 'support_ticket')]
#[ORM\UniqueConstraint(name: 'uniq_support_ticket_reference', columns: ['reference'])]
class SupportTicket
{
    /** The column is `VARCHAR(200)`, and the form says so; refused before the driver has to. */
    public const int MAX_SUBJECT = 200;

    /**
     * The column is `TEXT`, so this is a limit on a person rather than on
     * PostgreSQL.
     *
     * An operator reads these one after another on one screen, and a ticket that
     * is somebody's entire afternoon pasted into a box is the one that gets read
     * last. Generous enough that nobody with a real problem meets it.
     */
    public const int MAX_BODY = 8000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * What the collected copy is matched on — see the class docblock for why this
     * exists at all rather than the id being used for it.
     *
     * Hex rather than base64: it survives a URL, a log line, a `psql` session and
     * somebody reading it down a telephone. Not a UUID, because `symfony/uid` is
     * not a dependency here and adding one for a correlation key would be the
     * wrong trade.
     */
    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(name: 'raised_at')]
    private \DateTimeImmutable $raisedAt;

    public function __construct(
        #[ORM\Column(length: 200)]
        private string $subject,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        /** Who asked, by id, so a screen here can still link to somebody who is still here. */
        #[ORM\Column(name: 'raised_by_id', nullable: true)]
        private ?int $raisedById,
        /** And what they were called at the time. Never leaves this database. */
        #[ORM\Column(name: 'raised_by_label', type: Types::TEXT)]
        private string $raisedByLabel,
        ?\DateTimeImmutable $raisedAt = null,
    ) {
        $this->raisedAt = $raisedAt ?? new \DateTimeImmutable();
        $this->reference = bin2hex(random_bytes(16));
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRaisedById(): ?int
    {
        return $this->raisedById;
    }

    public function getRaisedByLabel(): string
    {
        return $this->raisedByLabel;
    }

    public function getRaisedAt(): \DateTimeImmutable
    {
        return $this->raisedAt;
    }
}

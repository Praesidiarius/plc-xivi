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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something the operator of this installation has to tell the people using it
 * (XIV-120, docs/architecture/identity-and-access.md §8.16).
 *
 * A maintenance window on Sunday, a module that gained payment terms, a trial
 * with a week left. All three are things whoever runs an installation knows and
 * a customer needs, and until this existed all three were an email somebody sent
 * by hand from their own client if they remembered.
 *
 * ## Why this entity is in `App\Registry\Entity` and not in the administration
 * surface
 *
 * Because that namespace is what a customer's own instance is allowed to read,
 * and this row's whole purpose is to be read there.
 *
 * §4.4 gives the customer-facing image's database role `SELECT` on the registry
 * tables and nothing else, and `App\Deployment\RegistryGrants` derives *which*
 * tables those are by asking the mapping for `App\Registry\Entity\` and no other
 * namespace. So the namespace is not a filing decision here — it is the grant.
 * A `Notice` declared in `Xivi\ControlPlane\Entity` would be on the *withheld*
 * list beside `operator` and `signup_request`, and the first customer dashboard
 * to render would meet a permission error.
 *
 * **This is [XIV-102] in the easy direction, and it is worth saying why it is
 * easier.** A purchase request is a *write* made by a customer's own request, so
 * §4.4 left it exactly one database — theirs — and an operator sees it only
 * because `tenant:purchase:collect` copies it across. A notice is written by an
 * operator, on the instance that owns the schema, and only *read* by a customer.
 * Reading the registry is precisely what the grant already permits, so there is
 * no collector, no interval, no copy, and no second row that can disagree with
 * the first. The constraint that made that ticket expensive makes this one
 * cheap, and neither of them is a workaround.
 *
 * ## Addressed to everybody, or to named customers
 *
 * {@see $everyTenant} is the switch and {@see $recipients} is the list, and the
 * two are deliberately not folded into one. "No recipient rows" and "everybody"
 * would be the same state on the screen and different states in fact: recipients
 * cascade away with a deprovisioned customer, so an announcement addressed to
 * three companies would silently become an announcement to the entire
 * installation on the day the last of them left. A boolean says which of the two
 * an operator meant and no cascade can change it.
 *
 * A third case — *"every customer who has the invoice module"* — is tempting,
 * was considered, and is not here. It is a different kind of question: the
 * catalogue knows which modules are *enabled* for a tenant (`Tenant::$enabledModules`),
 * but what a customer has actually installed is their own metadata (§6.1), one
 * boundary and one database away. That is a collector's job, which is a feature
 * rather than a case in an enum.
 *
 * ## When it is live
 *
 * Between {@see $publishedAt} and {@see $expiresAt}, and {@see withdraw()} is
 * simply the second of those being set to now. One concept rather than two: a
 * `withdrawn` boolean beside an expiry would be two ways of saying "stop showing
 * this", free to disagree, and every reader would have to remember both. The
 * cost is that an operator who withdraws something cannot tell afterwards
 * whether it ran out or was pulled — which is a fact about the past that nobody
 * has asked for.
 *
 * Publishing is immediate: {@see $publishedAt} is when the row was written.
 * Scheduling one for Friday is a real thing to want and is not built, because a
 * moment in the future is only useful with a way to see what is *pending*, and
 * the operator's screen would then have three states instead of two (§8.16).
 * The column is compared against `now` rather than assumed, so the day that
 * lands it is a form field.
 *
 * ## What it deliberately has not got
 *
 * **No image, no HTML, no link, no call to action.** An operator writing to
 * every customer of an ERP they depend on is a serious act, and the feature is
 * built to feel like one. The body is plain text rendered as plain text; the
 * page it appears on is the customer's own dashboard, above their work.
 *
 * **No summary column.** The previous iteration's `LicenseClientNotification`
 * had a title, a summary and a body, and a summary is a second thing to write
 * that can disagree with the first. A title and a body is what an announcement
 * is.
 *
 * **No severity.** Every notice is drawn the same way, so nothing here competes
 * for attention by claiming to be urgent. The day a genuine emergency channel is
 * wanted it should look different from this one rather than being this one with
 * a red flag set.
 *
 * **No read receipt.** See §8.16: knowing that somebody read this means
 * collecting a fact out of every customer's database, which is [XIV-102]'s
 * collector pointed the other way and is a feature rather than a column.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
#[ORM\Table(name: 'notice')]
#[ORM\Index(name: 'idx_notice_published', columns: ['published_at'])]
class Notice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The customers this notice names, empty when it is for everybody.
     *
     * A real entity rather than a `ManyToMany`, and the reason is mechanical:
     * a many-to-many's join table is not an entity, has no metadata, and is
     * therefore invisible to {@see \App\Deployment\RegistryGrants}, which reads
     * the mapping to work out what the customer-facing role may `SELECT`. The
     * grant would omit it and the failure would appear only in the deployment
     * that matters, and only for the customers a notice was addressed to by
     * name. `NoticeGrantsTest` asserts the table is on that list.
     *
     * @var Collection<int, NoticeRecipient>
     */
    #[ORM\OneToMany(targetEntity: NoticeRecipient::class, mappedBy: 'notice', cascade: ['persist', 'remove'])]
    private Collection $recipients;

    /**
     * When it stops being shown, or null for "until it is withdrawn".
     *
     * Nullable because most announcements are not about a moment that passes —
     * a release note is true afterwards — and a mandatory expiry would be a date
     * somebody invents to get past a form.
     */
    #[ORM\Column(name: 'expires_at', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string $authorLabel who wrote it, as they were called at the time —
     *                            a copy rather than a foreign key to `operator`,
     *                            because the reader this column exists for is a
     *                            customer and §4.4 gives their instance no access
     *                            to that table at all. A join would be unreadable
     *                            by the only party that needs the value, which is
     *                            a stronger reason than the ordinary one (that a
     *                            revoked or renamed operator must not rewrite the
     *                            authorship of something already published).
     */
    public function __construct(
        #[ORM\Column(length: 200)]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        #[ORM\Column(length: 32, enumType: NoticeAudience::class)]
        private NoticeAudience $audience,
        /** Everybody, rather than the customers in {@see $recipients} — see the class docblock. */
        #[ORM\Column(name: 'every_tenant')]
        private bool $everyTenant,
        #[ORM\Column(name: 'author_label', type: Types::TEXT)]
        private string $authorLabel,
        /** A date the customer is shown, which is half of what makes this an announcement rather than an advert. */
        #[ORM\Column(name: 'published_at')]
        private \DateTimeImmutable $publishedAt = new \DateTimeImmutable(),
    ) {
        $this->recipients = new ArrayCollection();
        $this->createdAt = $this->publishedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getAudience(): NoticeAudience
    {
        return $this->audience;
    }

    public function isForEveryTenant(): bool
    {
        return $this->everyTenant;
    }

    public function getAuthorLabel(): string
    {
        return $this->authorLabel;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, NoticeRecipient> */
    public function getRecipients(): Collection
    {
        return $this->recipients;
    }

    /**
     * Adds a customer to the list this notice names.
     *
     * Refuses on a notice addressed to everybody rather than quietly recording a
     * row nothing reads: the two ways of addressing are alternatives, and a row
     * that is both would be one whose meaning depends on which query found it.
     */
    public function addRecipient(Tenant $tenant): NoticeRecipient
    {
        if ($this->everyTenant) {
            throw new \LogicException(
                'A notice addressed to every customer cannot also name one; the two are alternatives.',
            );
        }

        $recipient = new NoticeRecipient($this, $tenant);
        $this->recipients->add($recipient);

        return $recipient;
    }

    /**
     * Whether this is one of the notices a dashboard should be drawing at $now.
     *
     * **The database asks this question too**, in
     * {@see \App\Registry\Notice\LiveNotices}, and that is the copy that matters
     * — a predicate applied after loading every notice in the installation is a
     * filter in the wrong place. This one exists for the operator's screen, which
     * has already loaded the rows because it is showing all of them, and for the
     * tests that would otherwise assert about a `WHERE` clause by reading it.
     *
     * Half-open on purpose: a notice is live at the instant it is published and
     * is not live at the instant it expires, so {@see withdraw()} — which sets
     * the expiry to now — takes effect on the next request rather than one second
     * later.
     */
    public function isLiveAt(\DateTimeImmutable $now): bool
    {
        return $this->publishedAt <= $now
            && ($this->expiresAt === null || $this->expiresAt > $now);
    }

    /**
     * Stops showing it, from now.
     *
     * Idempotent for a notice that has already stopped: withdrawing twice is one
     * withdrawal, and moving the expiry forward would be rewriting when it
     * stopped.
     */
    public function withdraw(\DateTimeImmutable $now): void
    {
        if ($this->expiresAt !== null && $this->expiresAt <= $now) {
            return;
        }

        $this->expiresAt = $now;
    }

    /**
     * Ends it at a moment of somebody's choosing, which is what a maintenance
     * window has.
     */
    public function expireAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }
}

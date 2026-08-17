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

namespace Xivi\ControlPlane\Entity;

use Doctrine\ORM\Mapping as ORM;
use Xivi\ControlPlane\Repository\SignupRequestRepository;

/**
 * Somebody has asked for an installation, and nothing has been created for them
 * yet (XIV-64).
 *
 * **This row is the entire output of the public endpoint**, which is the point
 * of the ticket rather than a limitation of it. The naive shape of self-service
 * signup is a form that calls `TenantProvisioner::provision()`, and that method
 * connects with the credential its own docblock describes as *"allowed to CREATE
 * DATABASE and CREATE ROLE; provisioning only"*. A form on the open internet
 * calling it would put the most privileged operation in the system one anonymous
 * HTTP request away from the least trusted caller there is. So the endpoint
 * writes one row in one table and stops; turning that row into a customer is
 * [XIV-98], and it runs where an operator can see it. docs/architecture.md §8.12
 * has the long version.
 *
 * ### What is stored, and what deliberately is not
 *
 * Enough to make a tenant out of later and nothing else. There is no IP address
 * here and no user agent: they would be the personal data of somebody who is not
 * yet a customer, kept for an abuse investigation nobody has a process for, and
 * the abuse controls this feature actually has — confirmation, one signup per
 * confirmed address, and a rate limiter — need none of it. The rate limiter's
 * own buckets are in a cache with an expiry, which is the right place for a fact
 * that stops mattering in an hour.
 *
 * There is also **no password and no user**. §8.8's invitation is what admits
 * the first person to a tenant, and it needs the tenant's own database to exist
 * before it can address anybody — so the first user is created by provisioning,
 * with nothing here to hold in the meantime.
 *
 * ### The confirmation token is a stored hash, and §8.8 argued the other way
 *
 * XIV-1 took Symfony's signed login link over a token table, with a real
 * argument: *"a token table stores something replayable and a signature stores
 * nothing at all"*. Three things make the answer come out differently here, and
 * they are worth writing down because the two features look alike from a
 * distance.
 *
 *   1. **There is no user to sign.** `LoginLinkHandlerInterface` signs an HMAC
 *      over a `UserInterface` loaded from a provider. A signup is not a user of
 *      anything — there is no tenant, therefore no database, therefore no
 *      `app_user` row — and inventing one so that the framework's helper could be
 *      used would be creating an account for somebody who may never confirm.
 *   2. **A row exists anyway.** The invitation's advantage was that *nothing* had
 *      to be written down. Here the signup itself is the record; the token is one
 *      more column on a row that has to exist either way, so the storage argument
 *      buys nothing.
 *   3. **What is stored is not the token.** {@see $confirmationTokenHash} holds a
 *      SHA-256 of 32 random bytes, so a dump of this table carries nothing anybody
 *      can present — which is the property §8.8 wanted, obtained by hashing rather
 *      than by not storing. A plain hash rather than a password hasher on purpose:
 *      the input is full-entropy random rather than something a person chose, so
 *      there is no dictionary for a slow KDF to defend against.
 *
 * `UriSigner` was the third candidate and loses to the same test as the first: a
 * signature over an id and an expiry cannot be invalidated when a second
 * submission supersedes the first, and superseding is the behaviour this feature
 * needs most (see {@see reissue()}).
 *
 * ### Two columns for one slug, and they cannot disagree
 *
 * {@see $slug} is what was asked for; {@see $reservedSlug} is what is held. Only
 * {@see confirm()} ever writes the second, and it writes the first — there is no
 * setter, so "reserved" is not a value anybody can put there, it is a
 * consequence of confirming.
 *
 * The point of the second column is the unique index on it. PostgreSQL treats
 * NULLs in a unique index as distinct, so every unconfirmed signup is NULL and
 * collides with nothing, while two confirmed signups can never hold one name.
 * The alternative — one column with a *partial* unique index, `WHERE status =
 * 'confirmed'` — says exactly the same thing in SQL and was rejected for a
 * reason that has nothing to do with SQL: `doctrine:schema:validate` compares
 * this mapping against the database, a partial index's predicate does not
 * survive that round trip intact, and a schema check that reports a permanent
 * false difference is a schema check people learn to ignore.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: SignupRequestRepository::class)]
#[ORM\Table(name: 'signup_request')]
// One live signup per address, whatever state it is in. This is the constraint
// that makes a second submission a *replacement* rather than a second row, and
// it is also half of "a confirmed address may hold one unprovisioned signup at a
// time" — the other half being that [XIV-98] removes the row when it provisions.
#[ORM\UniqueConstraint(name: 'uniq_signup_request_email', columns: ['email'])]
// And one confirmed signup per name. NULL for everything unconfirmed, which is
// how the schema says "this row holds nothing".
#[ORM\UniqueConstraint(name: 'uniq_signup_request_reserved_slug', columns: ['reserved_slug'])]
// Looked up on every confirmation click, by a value nobody can guess.
#[ORM\Index(name: 'idx_signup_request_token', columns: ['confirmation_token_hash'])]
#[ORM\HasLifecycleCallbacks]
class SignupRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: SignupStatus::class, length: 16)]
    private SignupStatus $status = SignupStatus::Pending;

    /**
     * The name this signup holds, once its address has answered.
     *
     * NULL until then, and unique across the table. See the class docblock for
     * why the reservation is a column of its own rather than a predicate on the
     * index over {@see $slug}.
     */
    #[ORM\Column(name: 'reserved_slug', length: 63, nullable: true)]
    private ?string $reservedSlug = null;

    /**
     * SHA-256, hex, of the token that was mailed. Never the token itself.
     *
     * Kept after confirmation rather than cleared, and that is the replay
     * decision: a second click on the same link finds the same row, sees it is
     * already confirmed, and says so. Clearing it would turn the ordinary
     * accidents — a double click, a forwarded mail, a corporate link scanner
     * that fetches every URL in an inbox before the human sees it — into
     * "this link is not valid", which is the failure that generates support mail
     * and teaches nobody anything. What the token can do after confirmation is
     * exactly nothing: {@see confirm()} is a no-op on a confirmed row.
     */
    #[ORM\Column(name: 'confirmation_token_hash', length: 64)]
    private string $confirmationTokenHash;

    #[ORM\Column(name: 'confirmation_expires_at')]
    private \DateTimeImmutable $confirmationExpiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        /** Lowercased and trimmed by the intake before it ever reaches here. */
        #[ORM\Column(length: 180)]
        private string $email,
        /** What they call themselves, and what the slug was derived from. */
        #[ORM\Column(length: 255)]
        private string $companyName,
        /** The name they asked for. Hostname-safe; see `SelfServiceSlug`. */
        #[ORM\Column(length: 63)]
        private string $slug,
        /**
         * Which plan they asked for.
         *
         * Same column width and same default as `Tenant::$plan`, because it
         * becomes that field. Billing is out of scope here and the intake still
         * refuses to pretend there is only one plan: the value is checked against
         * the installation's configured list before it is stored, so an unknown
         * plan is a refusal at the door rather than a tenant created on a plan
         * nobody sells.
         */
        #[ORM\Column(length: 64)]
        private string $plan,
        /**
         * Which language to write to them in.
         *
         * The calling site knows it — it is the language the visitor was reading
         * the form in — and there is nowhere else to get it from: this person has
         * no account anywhere, so there is no stored preference and no session.
         * Falls back to the installation's default when the caller says nothing.
         */
        #[ORM\Column(length: 16)]
        private string $locale,
        #[\SensitiveParameter]
        string $confirmationTokenHash,
        \DateTimeImmutable $confirmationExpiresAt,
    ) {
        $this->confirmationTokenHash = $confirmationTokenHash;
        $this->confirmationExpiresAt = $confirmationExpiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * A second submission from the same address, on the row the first one left.
     *
     * **The previous link stops working here**, because the hash it was checked
     * against is overwritten — the same shape §8.8 gives an invitation with
     * `invitation_seed`, and for the same reason: "I asked for another one" has
     * to be the way to fix a confirmation mail that went astray, and it is not
     * if the first link is still live in whatever mailbox it reached.
     *
     * Everything else is overwritten too, deliberately. Somebody who submits
     * twice is correcting something — the company name, the slug they wanted,
     * the plan — and a row that kept the first answers while mailing a link that
     * confirms them would confirm a signup its owner believes they replaced.
     *
     * Refused outright on a confirmed row: at that point the address holds a name
     * and rewriting it here would release the reservation as a side effect of a
     * form submission. The intake answers `address_already_registered` instead.
     */
    public function reissue(
        string $companyName,
        string $slug,
        string $plan,
        string $locale,
        #[\SensitiveParameter]
        string $confirmationTokenHash,
        \DateTimeImmutable $confirmationExpiresAt,
    ): void {
        if ($this->status === SignupStatus::Confirmed) {
            throw new \LogicException(sprintf('Signup %d is confirmed and cannot be reissued.', $this->id ?? 0));
        }

        $this->companyName = $companyName;
        $this->slug = $slug;
        $this->plan = $plan;
        $this->locale = $locale;
        $this->confirmationTokenHash = $confirmationTokenHash;
        $this->confirmationExpiresAt = $confirmationExpiresAt;
        $this->touch();
    }

    /**
     * The address answered, so the name is now theirs.
     *
     * **Idempotent, and that is the replay handling.** Clicking twice, or having
     * a mail scanner click first, changes nothing the second time: the row is
     * already confirmed, `confirmed_at` keeps the moment the *first* click
     * happened, and the reservation is not rewritten. The caller can tell the two
     * apart from the return value and says so on the page, which is a better
     * answer than an error for something that is not one.
     *
     * @return bool whether this call is what confirmed it
     */
    public function confirm(): bool
    {
        if ($this->status === SignupStatus::Confirmed) {
            return false;
        }

        $this->status = SignupStatus::Confirmed;
        $this->reservedSlug = $this->slug;
        $this->confirmedAt = new \DateTimeImmutable();
        $this->touch();

        return true;
    }

    /**
     * Whether the confirmation window has closed on an unconfirmed signup.
     *
     * Asked of a *pending* row only. A confirmed one is finished with its window
     * — the address answered inside it — and reporting a confirmed signup as
     * expired because somebody opened the mail again a week later would be
     * answering a question nobody asked.
     */
    public function confirmationHasExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->status === SignupStatus::Pending
            && $this->confirmationExpiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getReservedSlug(): ?string
    {
        return $this->reservedSlug;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getStatus(): SignupStatus
    {
        return $this->status;
    }

    public function getConfirmationExpiresAt(): \DateTimeImmutable
    {
        return $this->confirmationExpiresAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

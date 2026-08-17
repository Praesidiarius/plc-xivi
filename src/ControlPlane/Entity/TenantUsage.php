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

namespace App\ControlPlane\Entity;

use App\ControlPlane\Repository\TenantUsageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one customer was using, the last time anybody went and looked (XIV-59).
 *
 * **A row here is a collection, not a tenant.** That is the whole reason this is
 * a table of its own rather than five more columns on {@see Tenant}, and the
 * argument is worth having out loud because the columns would have been less
 * work:
 *
 *   * Every value on this row is qualified by *when it was read*. A user count
 *     with no collection time beside it is a number somebody will act on without
 *     knowing whether it is from this morning or from March, and §8.11 argues
 *     that a stale figure presented as current is worse than no figure at all.
 *     `collected_at` is therefore not an extra column, it is the column the
 *     others only mean anything relative to — and a fact about the *run* rather
 *     than about the customer.
 *   * The same goes for the failure. A tenant whose database did not answer has
 *     not changed; the collection failed. Writing that onto `tenant` would be
 *     recording a property of a process on a row that describes a customer, and
 *     the first person to read `tenant.failure` would reasonably think the
 *     customer was broken.
 *   * **A tenant that has never been collected has no row here at all**, which
 *     is a state the columns could not have expressed without a sixth nullable
 *     column meaning "the nulls above are real nulls". Absence says it exactly:
 *     provision a customer at ten past nine and the page says *not collected
 *     yet* until the collector next runs, rather than showing them as having
 *     nothing in them.
 *
 * ## Three states, and the page can tell them apart
 *
 * No row — never collected. A row with `failure` set — the collection was tried
 * at `collected_at` and the database did not answer. A row with `failure` null —
 * these are the figures, as of `collected_at`, and a zero in them is a real zero.
 * That last distinction is the same one [XIV-39] drew for a mail that was not
 * sent: *nothing happened* and *we do not know* are different answers, and a
 * screen that renders them alike is one that gets acted on wrongly.
 *
 * ## The failure is stored as a class name, and deliberately not as a message
 *
 * A driver error carries the connection in it: `could not translate host name
 * "db-07.internal" to address`, or a message naming the port and the role.
 * {@see \App\ControlPlane\View\TenantSummary} exists precisely so that a `Tenant`
 * — and therefore its DSN — cannot reach an HTML page, and storing the driver's
 * own words here would smuggle those same parts back into the control plane by a
 * side door, one row further along, waiting for somebody to render them "just for
 * debugging". So what is kept is the exception's class, which says whether the
 * database was unreachable or the schema was missing and names nothing. The full
 * message goes to the terminal of whoever ran the collection, who is already
 * somebody with shell access to the DSN.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: TenantUsageRepository::class)]
#[ORM\Table(name: 'tenant_usage')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_usage_tenant', columns: ['tenant_id'])]
class TenantUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** When the run that produced (or failed to produce) the figures below ran. */
    #[ORM\Column]
    private \DateTimeImmutable $collectedAt;

    /**
     * Null whenever `failure` is set, and never otherwise — the collector writes
     * both halves in one call so the two cannot drift apart.
     */
    #[ORM\Column(nullable: true)]
    private ?int $userCount = null;

    /**
     * The most recent sign-in across all of that customer's users, or null.
     *
     * Null means two things that are the same thing here: nobody has ever signed
     * in, or there is nobody to sign in. Both read as *never*, which is the
     * honest answer either way — and it is only reachable on a successful
     * collection, so it is never confused with "we could not look".
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $recordCount = null;

    /**
     * The same total, broken down by module key.
     *
     * Kept because the total on its own answers "how much" and not "of what", and
     * a customer with nine thousand contacts and no invoices is a different
     * customer from one with the reverse. Still counts and still no content: the
     * keys are module names this build already knows, and the values are
     * integers.
     *
     * @var array<string, int>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $recordsByModule = null;

    /**
     * The class of whatever went wrong, or null when nothing did.
     *
     * See the class docblock: the class rather than the message, because the
     * message names the host and the role.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failure = null;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
    ) {
        // Overwritten by the first `record*()` call. Set here so the object is
        // never in a state where it claims a collection time it has not got.
        $this->collectedAt = new \DateTimeImmutable();
    }

    /**
     * The figures, as of now.
     *
     * Clears the failure, because this row is the *last attempt* and nothing
     * else: a success following a failure means the database is answering again,
     * and leaving the old failure beside fresh figures would say something that
     * is no longer true.
     *
     * @param array<string, int> $recordsByModule
     */
    public function record(int $userCount, ?\DateTimeImmutable $lastLoginAt, array $recordsByModule): void
    {
        $this->collectedAt = new \DateTimeImmutable();
        $this->userCount = $userCount;
        $this->lastLoginAt = $lastLoginAt;
        $this->recordsByModule = $recordsByModule;
        $this->recordCount = array_sum($recordsByModule);
        $this->failure = null;
    }

    /**
     * It was tried, at this moment, and the database did not answer.
     *
     * **The previous figures are dropped rather than kept beside the failure**,
     * which is the decision worth arguing with. Keeping them would let the page
     * show yesterday's numbers with a warning attached — more information, and
     * the wrong kind: the figures would then be as old as the last *success*
     * while the timestamp beside them says the last *attempt*, and a reader who
     * takes in one and not the other has been misled by the screen rather than by
     * their own carelessness. So a failed collection leaves nothing but the fact
     * that it failed and when, which is the only thing this row still knows.
     */
    public function recordFailure(string $exceptionClass): void
    {
        $this->collectedAt = new \DateTimeImmutable();
        $this->userCount = null;
        $this->lastLoginAt = null;
        $this->recordCount = null;
        $this->recordsByModule = null;
        $this->failure = $exceptionClass;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getCollectedAt(): \DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function hasFailed(): bool
    {
        return $this->failure !== null;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function getUserCount(): ?int
    {
        return $this->userCount;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getRecordCount(): ?int
    {
        return $this->recordCount;
    }

    /** @return array<string, int> */
    public function getRecordsByModule(): array
    {
        return $this->recordsByModule ?? [];
    }
}

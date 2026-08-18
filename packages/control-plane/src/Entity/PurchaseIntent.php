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

use App\Registry\Entity\Tenant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xivi\ControlPlane\Repository\PurchaseIntentRepository;

/**
 * A customer's request to buy a module, as the last collection found it
 * (XIV-102).
 *
 * **A row here is a collection, not a request.** That is {@see TenantUsage}'s
 * sentence and it is the same sentence for the same reason: the request itself is
 * a row in the customer's own database
 * ({@see \App\Tenant\Entity\ModulePurchaseIntent}), because §4.4's grant leaves
 * nowhere else for a customer's own write to go, and this is a copy of it taken
 * at {@see $collectedAt} by `tenant:purchase:collect`.
 *
 * Everything that follows from that is [XIV-59]'s, unchanged:
 *
 *   * The figure beside a request is only true as of the moment it was read, so
 *     the collection time is drawn on the page beside every row rather than being
 *     an implementation detail. A stale request presented as current is worse
 *     than no request, and here "stale" has a specific shape — the customer may
 *     have been served ten minutes ago by an operator who did it by hand.
 *   * The fan-out happens in a command that nobody is waiting on, so the
 *     operator's page stays one request against one database, and §7.4's
 *     guarantee that a request resolves one tenant stays a *consequence* of how
 *     requests work rather than a rule with an exception in it.
 *
 * ## What crosses and what does not
 *
 * The tenant, the module, the copied price and the two moments. **Not who asked**
 * — the tenant-side row carries the person's id and their name at the time, and
 * neither leaves their database. §8.11 drew that line for the usage figures and
 * the argument here is the same one: an operator page exists to say *what was
 * asked for*, and the moment it says *by whom*, the control plane has become a
 * way to read a customer's own people without their knowing.
 *
 * The honest consequence is written down rather than hidden: an operator looking
 * at this list knows which company wants which module and does **not** know who
 * to write to. They reach the customer the way they already reach them, which is
 * the arrangement the registry describes. A "contact" column here would be one
 * more copy of a customer's personal data in a database they cannot see, kept for
 * a conversation that happens somewhere else anyway.
 *
 * ## `installed` is observed, not reported
 *
 * The collector is already inside the customer's database and already knows what
 * they have (§6.1: their own metadata is the truth), so whether the request has
 * been fulfilled is *read* rather than tracked. That is deliberate and it is
 * [XIV-98]'s argument against a `provisioned` status on a signup: a status column
 * would be a second copy of a fact the customer's database already holds, free to
 * disagree with it — and the disagreement would be silent, on the one screen an
 * operator opens to find out whether they still owe somebody something.
 *
 * So the operator installs the module by whatever means (the store cannot; that
 * is the point of the ticket), and the next collection sees it and says so. There
 * is no button here that marks anything done, because there is nothing to mark:
 * the world is the record.
 *
 * ## The row is removed when the request is
 *
 * A collection that finds no request for a module the customer previously asked
 * about deletes this row. The alternative — keeping it as history — would need a
 * retention policy nobody has written and would make this table a log rather than
 * a queue, which is exactly what {@see TenantUsage} declined to become for the
 * same reason. Nothing withdraws a request today, so this path exists for the
 * customer whose database was rebuilt and for the row `down()` left behind.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: PurchaseIntentRepository::class)]
#[ORM\Table(name: 'purchase_intent')]
// One row per customer per module, which is the shape of the thing being copied:
// the tenant side has a unique index on the module key, so two rows here for one
// pair could only ever mean the collector wrote twice.
#[ORM\UniqueConstraint(name: 'uniq_purchase_intent_tenant_module', columns: ['tenant_id', 'module_key'])]
class PurchaseIntent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The decimal string the customer was shown, copied from their row unchanged (§5.9). */
    #[ORM\Column(name: 'price_amount', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $priceAmount = '0.00';

    /** The ISO 4217 code it was shown in, or null when this deployment has never set one. */
    #[ORM\Column(name: 'price_currency', length: 3, nullable: true)]
    private ?string $priceCurrency = null;

    /** When the customer last said they wanted it — their clock's answer, copied. */
    #[ORM\Column(name: 'requested_at')]
    private \DateTimeImmutable $requestedAt;

    /** When they first said it, which is how long this has been outstanding. */
    #[ORM\Column(name: 'first_requested_at')]
    private \DateTimeImmutable $firstRequestedAt;

    /**
     * Whether the customer had the module when this collection ran.
     *
     * Observed rather than reported — see the class docblock. True is the end of
     * a request's life and nothing here uninstalls anything (§6.2), so this never
     * goes back to false for a customer who was actually served.
     */
    #[ORM\Column]
    private bool $installed = false;

    /** When the run that produced the values above ran. Every one of them is relative to it. */
    #[ORM\Column(name: 'collected_at')]
    private \DateTimeImmutable $collectedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        /**
         * The catalogue key, which names a module of the build rather than a row
         * anywhere — so there is nothing here to point a foreign key at, in
         * either database.
         */
        #[ORM\Column(name: 'module_key', length: 63)]
        private string $moduleKey,
    ) {
        // Overwritten by the first record() call, and set here so the object is
        // never in a state where it claims a collection time it has not got —
        // TenantUsage's constructor takes the same care for the same reason.
        $this->collectedAt = new \DateTimeImmutable();
        $this->requestedAt = $this->collectedAt;
        $this->firstRequestedAt = $this->collectedAt;
    }

    /**
     * What the last collection found, written in one call.
     *
     * One method rather than six setters, because these values are only ever
     * true together: they all come out of one switch into one customer's
     * database at one moment, and a caller that could set three of them would be
     * a caller that could leave a price from March beside a collection time from
     * August.
     */
    public function record(
        string $priceAmount,
        ?string $priceCurrency,
        \DateTimeImmutable $requestedAt,
        \DateTimeImmutable $firstRequestedAt,
        bool $installed,
    ): void {
        $this->priceAmount = $priceAmount;
        $this->priceCurrency = $priceCurrency;
        $this->requestedAt = $requestedAt;
        $this->firstRequestedAt = $firstRequestedAt;
        $this->installed = $installed;
        $this->collectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getModuleKey(): string
    {
        return $this->moduleKey;
    }

    public function getPriceAmount(): string
    {
        return $this->priceAmount;
    }

    public function getPriceCurrency(): ?string
    {
        return $this->priceCurrency;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getFirstRequestedAt(): \DateTimeImmutable
    {
        return $this->firstRequestedAt;
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function getCollectedAt(): \DateTimeImmutable
    {
        return $this->collectedAt;
    }
}

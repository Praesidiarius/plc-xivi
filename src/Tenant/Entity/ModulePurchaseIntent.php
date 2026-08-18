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

use App\Registry\Pricing\ModulePrice;
use App\Tenant\Repository\ModulePurchaseIntentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer has said they want a module that costs money, and nothing has been
 * installed and nothing has been charged (XIV-102).
 *
 * **This row is the entire output of pressing the button**, which is the ticket
 * rather than a limitation of it, and it is [XIV-64]'s shape one layer down. That
 * ticket asked what a public signup form should be allowed to do and answered
 * *record an intent, do nothing privileged, and let a non-public process act on
 * it* — because "anyone may ask" and "the thing happens" are deliberately not the
 * same event. Here the asker is a customer rather than a stranger and the
 * privileged act is installing a module they have not paid for, but the sentence
 * survives the substitution word for word. §8.15 has the long version, including
 * the alternative that was rejected: installing anyway and marking the
 * installation unpaid.
 *
 * ## Why this table is in the customer's own database and not in the control
 * plane
 *
 * Because §4.4 leaves no other answer, and the constraint is a good one rather
 * than an obstacle worked around. The customer-facing image's database role holds
 * `SELECT` on the registry tables and nothing else — no `INSERT` anywhere in the
 * control-plane database, on any table, present or future. A tenant's request
 * *cannot* write a row over there, and that is the guarantee [XIV-96] exists to
 * make, so a feature whose first requirement is a write from a customer's request
 * has exactly one database available to it: theirs.
 *
 * That turns out to be where it belonged anyway. A purchase request is a thing
 * *this customer* did, they are the party who should be able to see it on their
 * own screen — "you asked for this on Tuesday, nobody has been charged" is the
 * answer to the question they will have — and a module is installed into their
 * database, so the record of having asked for one sits beside the record of
 * having it. How an operator comes to see it is
 * {@see \Xivi\ControlPlane\Purchase\PurchaseIntentCollector}, which is [XIV-59]'s
 * answer to the same question about usage figures and is deliberately the same
 * answer.
 *
 * ## The price is a copy, and §6.5 asked for it by name
 *
 * [XIV-101] left exactly one instruction for this ticket: *"when a purchase is
 * recorded, the price goes onto that record as a **copy**, exactly as an invoice
 * stores its own due date (§5.16) and its own totals (§5.9). Nothing about a sale
 * is ever recomputed from this row afterwards."*
 *
 * So {@see $priceAmount} and {@see $priceCurrency} are what was on the screen at
 * the moment somebody pressed the button, frozen. An operator who raises the
 * module's price the next morning has changed what the *next* customer will be
 * quoted and has not changed this one — which is the only reading under which the
 * figure the customer saw means anything at all. The alternative, reading the
 * live price whenever this row is displayed, would let a request made at 49.00 be
 * fulfilled at 59.00 with nothing anywhere recording that the two numbers were
 * ever different.
 *
 * The currency is copied for the same reason and is **nullable for the same
 * reason it is nullable everywhere else**: a deployment that has never set
 * `PRICE_CURRENCY` prices in nothing, §8.6 refuses to guess one, and a copy that
 * invented `CHF` here would be inventing it on the one row somebody might later
 * treat as a commitment. Null means what it says: the number was shown bare,
 * because the installation never said what it sells in.
 *
 * ## One row per module, rewritten rather than repeated
 *
 * The unique index on {@see $moduleKey} is the whole of it. Somebody who asks
 * twice is not making two requests; they are asking again, most likely because
 * nobody got back to them — and a table that grew a row per press would turn an
 * impatient customer into an operator's queue full of duplicates, which is the
 * failure mode that makes a queue stop being read. So {@see reissue()} overwrites
 * in place, exactly as {@see \Xivi\ControlPlane\Entity\SignupRequest::reissue()}
 * does for the same reason one level up, and the moment recorded is the *latest*
 * ask rather than the first: what an operator needs to know is how long this
 * person has been waiting since they last said so.
 *
 * The copied price is refreshed with it, because a second ask is a fresh quote —
 * the customer is looking at today's figure when they press the button, and a row
 * that kept February's number while they read August's would be a copy of
 * something nobody was ever shown.
 *
 * ## What is deliberately not here
 *
 * **No status.** There is no `fulfilled`, no `declined` and no `paid`, and that
 * is not an omission to be filled in later by whoever needs one. Fulfilment is
 * observable without being stored: the module is either installed in this
 * database or it is not, `MetadataRepository` answers that, and a status column
 * would be a second copy of that fact free to disagree with it — which is
 * [XIV-98]'s argument for refusing a `provisioned` status on a signup, reused
 * unchanged. Nothing here uninstalls anything (§6.2), so once the answer is yes
 * it stays yes.
 *
 * **No amount paid, no payment reference, no gateway anything.** There is no
 * payment gateway in this system, and a nullable `paid_at` sitting in the schema
 * waiting for one is a column that makes the screen above it look like it
 * settles money. It does not. When a gateway lands it brings its own record of a
 * transaction, and this row will be the thing that transaction points at.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: ModulePurchaseIntentRepository::class)]
#[ORM\Table(name: 'module_purchase_intent')]
// One live request per module. Asking again rewrites this row; see reissue().
#[ORM\UniqueConstraint(name: 'uniq_module_purchase_intent_module', columns: ['module_key'])]
#[ORM\HasLifecycleCallbacks]
class ModulePurchaseIntent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The figure that was on the screen, as a decimal string at two places.
     *
     * `NUMERIC(12, 2)` in the column and never a float on the way in or out,
     * which is §5.9's rule and is the same representation {@see ModulePrice}
     * carries — this is a copy of that value, so anything else would be a
     * conversion nobody asked for. Not nullable: an intent only exists for a
     * module that costs money, and {@see ModulePricing::Priced} is the one case
     * that has an amount.
     */
    #[ORM\Column(name: 'price_amount', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $priceAmount;

    /**
     * The ISO 4217 code the figure above was shown in, or null when the
     * deployment has never said.
     *
     * Three characters, upper case, exactly as `PriceCurrency::code()` hands it
     * over. See the class docblock for why null is a real answer here rather than
     * a gap to be defaulted.
     */
    #[ORM\Column(name: 'price_currency', length: 3, nullable: true)]
    private ?string $priceCurrency;

    /**
     * Who pressed the button, by id, and what they were called at the time.
     *
     * Two columns rather than a foreign key to `app_user`, which is
     * {@see FollowUp}'s pattern and is followed here for its reason rather than
     * for its shape: a person who leaves the company should not take the record
     * of a purchase request with them, and a label copied at the moment is the
     * name the request was made under even if that person has since been renamed.
     * The id is kept beside it so a screen can still link to somebody who is
     * still there.
     *
     * **Neither of these ever leaves this database.** The collector that shows
     * an operator this request copies the tenant and the module across and stops
     * — §8.11 draws the line at *how much* rather than *what*, and a person's
     * name on an operator's screen is on the wrong side of it. See
     * {@see \Xivi\ControlPlane\Entity\PurchaseIntent}.
     */
    #[ORM\Column(name: 'requested_by_id', nullable: true)]
    private ?int $requestedById;

    #[ORM\Column(name: 'requested_by_label', type: Types::TEXT)]
    private string $requestedByLabel;

    /**
     * When they last said so.
     *
     * The latest ask rather than the first, because {@see reissue()} overwrites —
     * see the class docblock. `created_at` is kept separately so the two are
     * distinguishable on a row somebody has asked about twice.
     */
    #[ORM\Column(name: 'requested_at')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        /** The catalogue key of the module, not a module definition: they have not got one. */
        #[ORM\Column(name: 'module_key', length: 63)]
        private string $moduleKey,
        ModulePrice $price,
        ?string $currency,
        ?int $requestedById,
        string $requestedByLabel,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->priceAmount = self::amountOf($price);
        $this->priceCurrency = $currency;
        $this->requestedById = $requestedById;
        $this->requestedByLabel = $requestedByLabel;
        $this->requestedAt = $this->createdAt;
    }

    /**
     * They asked again, on the row the first ask left.
     *
     * Everything about the request is rewritten, including the copied price:
     * somebody pressing the button today is reading today's figure, and a row
     * that kept the old one would be a copy of a screen nobody saw. What is not
     * rewritten is {@see $createdAt}, which is how long this has genuinely been
     * outstanding — the number an operator wants when they are working out how
     * badly this went.
     */
    public function reissue(
        ModulePrice $price,
        ?string $currency,
        ?int $requestedById,
        string $requestedByLabel,
    ): void {
        $this->priceAmount = self::amountOf($price);
        $this->priceCurrency = $currency;
        $this->requestedById = $requestedById;
        $this->requestedByLabel = $requestedByLabel;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRequestedById(): ?int
    {
        return $this->requestedById;
    }

    public function getRequestedByLabel(): string
    {
        return $this->requestedByLabel;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The decimal string out of a price that is certain to have one.
     *
     * Loud rather than lenient: an intent for a module that is free, unpriced or
     * withdrawn from sale is a request nobody could have made through the store,
     * so it is a caller bug rather than a value to store a null for. Every path
     * that reaches here has already been past
     * {@see \App\Store\StoreOffer::isBuyable()}.
     */
    private static function amountOf(ModulePrice $price): string
    {
        return $price->amount ?? throw new \InvalidArgumentException(sprintf(
            'A purchase intent needs a price to copy, and a module that is "%s" has none.',
            $price->pricing->value,
        ));
    }
}

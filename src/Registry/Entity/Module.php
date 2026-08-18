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

use App\Registry\Pricing\ModulePrice;
use App\Registry\Pricing\ModulePricing;
use App\Registry\Repository\ModuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the platform has decided about one module: its state (XIV-7), and since
 * [XIV-101] what it costs.
 *
 * The row is *not* the module. What modules exist is a property of the build, and
 * `Xivi\Core\Module\ModuleRegistry` answers it; this table only carries the part
 * of the answer code cannot hold, because publishing is an operational decision
 * rather than a change to the module — the same reason a tenant's plan and status
 * live out here (§4) while its shape lives in code (§6.1).
 *
 * Which is why **a module with no row is in development**: that is the default a
 * new module gets for free, without a sync step whose only job would be to write
 * the answer down. A row appears the first time somebody decides otherwise, and
 * `App\Registry\Catalog\ModuleCatalog` is what joins the two halves.
 *
 * ## The price sits beside the state because it is the same kind of fact
 *
 * A price is not on `ModuleBlueprint` and must not move there. A blueprint ships
 * identically to every deployment, so a price in `packages/invoice/` would be a
 * price every installation inherits and none of them chose — one company sells
 * the invoice module, the next bundles it into a contract, a third runs this for
 * itself alone and sells nothing at all. That is the argument [XIV-7] already
 * made about `state` (§6.2) and it transfers word for word.
 *
 * **A row's two decisions are independent and the defaults differ.** No row at
 * all means `development`; a row that exists for some *other* reason — somebody
 * published the module — means {@see ModulePricing::Unpriced}, because the price
 * cannot borrow "no row" as its default when the row is already there for the
 * state. So the absence of a decision is a value in the column rather than the
 * absence of one, and it is deliberately not `free`.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: ModuleRepository::class)]
#[ORM\Table(name: 'module')]
#[ORM\UniqueConstraint(name: 'uniq_module_key', columns: ['module_key'])]
#[ORM\HasLifecycleCallbacks]
class Module
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * How this deployment sells the module, and — for exactly one of the four
     * cases — how much for.
     *
     * Two columns rather than one because they are two different kinds of thing:
     * the decision is a closed set the code matches on, and the amount is money.
     * They are only ever handed out together, as a {@see ModulePrice}, which is
     * what refuses the combinations that cannot both be true.
     */
    #[ORM\Column(enumType: ModulePricing::class, length: 32)]
    private ModulePricing $pricing = ModulePricing::Unpriced;

    /**
     * A decimal string at two places, never a float — §5.9's representation, the
     * same one `CurrencyFieldType` stores and `Money\Amount` computes in.
     * Doctrine's `decimal` type hands PHP a string, which is exactly what is
     * wanted: nothing on the path from this column to a rendered price ever
     * becomes binary floating point, so 19.90 stays 19.90.
     *
     * Null unless {@see $pricing} is `priced`, and null is enforced in
     * {@see ModulePrice::fromStorage()} rather than trusted.
     */
    #[ORM\Column(name: 'price_amount', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $priceAmount = null;

    public function __construct(
        /**
         * The blueprint key, e.g. `contact`. No foreign key exists or could: the
         * thing it names lives in the build, not in a table.
         */
        #[ORM\Column(name: 'module_key', length: 64)]
        private string $key,
        #[ORM\Column(enumType: ModuleState::class, length: 32)]
        private ModuleState $state = ModuleState::Development,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getState(): ModuleState
    {
        return $this->state;
    }

    /**
     * Kept even when it goes back to development, rather than deleting the row:
     * the two are the same to every reader, and the timestamp of a module being
     * pulled out of the store is worth more than a tidy table.
     */
    public function setState(ModuleState $state): void
    {
        $this->state = $state;
    }

    /**
     * The two price columns as the one fact they are (XIV-101).
     *
     * Rebuilt on every read rather than held as a field, so that a row hydrated
     * out of a database somebody has edited by hand fails here — where the
     * message can say which two halves disagree — rather than in whatever tried
     * to render it.
     */
    public function getPrice(): ModulePrice
    {
        return ModulePrice::fromStorage($this->pricing, $this->priceAmount);
    }

    /**
     * Changes what this deployment charges for the module, and nothing else.
     *
     * **This writes two columns of one control-plane row and reaches nothing
     * else**, which is the invariant [XIV-101] is built around rather than a
     * description of the current implementation. Raising a module's price must
     * not restate what an existing customer is deemed to owe and must not
     * uninstall anything: a customer's modules live in their own database, are
     * put there by `ModuleInstaller`, and are read back out of their own
     * metadata (§6.1, §6.3). Nothing on that path consults this column, and
     * `ModulePriceTest` is what keeps that true.
     *
     * It is [XIV-67]'s argument about payment terms, arriving at the same place
     * from the other side: what was agreed is a fact about the transaction rather
     * than a live lookup. When [XIV-102] records a purchase, the price goes onto
     * that record as a **copy**, exactly as an invoice stores its own due date
     * (§5.16) and its own totals (§5.9), and nothing about a sale is ever
     * recomputed from this row afterwards.
     */
    public function setPrice(ModulePrice $price): void
    {
        $this->pricing = $price->pricing;
        $this->priceAmount = $price->amount;
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

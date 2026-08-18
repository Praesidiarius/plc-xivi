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

namespace App\Store;

use App\Registry\Pricing\ModulePrice;
use App\Tenant\Entity\ModulePurchaseIntent;
use Xivi\Core\Module\ModuleBlueprint;

/**
 * One module as the store shows it: what it is, what it costs, whether this
 * customer has it, and whether they could (XIV-6, XIV-102).
 *
 * Two facts about the same module from two different places, which is the whole
 * reason this exists rather than a template reaching for both. What the build
 * offers comes from the control plane crossed with the registry
 * ({@see \App\Registry\Catalog\ModuleCatalog}); whether this customer has it,
 * and whether they have what it needs, comes from their **own** database and
 * nowhere else. Joining them in a value object is what keeps the controller from
 * being the place that knows the difference.
 *
 * **[XIV-102] added a third fact of the first kind and a fourth of the second**,
 * and the split holds for both. The price is the control plane's answer, read
 * through `ModuleCatalog::price()` and through nothing else — §6.5 is emphatic
 * that there is one seam onto the `module` table and this class is not allowed to
 * become a second one. Whether this customer has already asked to buy it is a row
 * in *their* database, exactly like {@see $installed}, and for the same reason
 * (§4.4: a customer's request cannot write the control plane, so the request
 * lives where the customer does).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class StoreOffer
{
    /**
     * @param ModulePrice           $price        what this deployment charges,
     *                                            from the catalogue (§6.5). Never
     *                                            null: a module nobody has priced
     *                                            is `unpriced`, which is a
     *                                            decision's absence rather than
     *                                            the field's — and is anyway not
     *                                            offered, so it never reaches here
     * @param list<Requirement>     $requirements what this module needs, each saying
     *                                            whether the customer has it
     * @param list<PresetOffer>     $presets      empty for a module that ships none,
     *                                            which installs every field it has
     * @param list<string>          $collections  labels of the child tables installing
     *                                            creates whatever preset is chosen (§6.1)
     * @param ?ModulePurchaseIntent $requested    this customer's outstanding request
     *                                            to buy it, or null. Only ever set for
     *                                            a priced module, because nothing
     *                                            records a request for any other kind
     */
    public function __construct(
        public ModuleBlueprint $blueprint,
        public string $label,
        public bool $installed,
        public ModulePrice $price,
        public array $requirements,
        public array $presets = [],
        public array $collections = [],
        public ?ModulePurchaseIntent $requested = null,
    ) {
    }

    public function key(): string
    {
        return $this->blueprint->key;
    }

    /** @return list<Requirement> */
    public function missingRequirements(): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (Requirement $requirement): bool => !$requirement->installed,
        ));
    }

    /**
     * Whether the install button is a real offer.
     *
     * The store deliberately does not offer an install it already knows the
     * engine would refuse (XIV-23): `ModuleInstaller` names what is missing, but
     * finding that out from a failed submit — after choosing a preset that cannot
     * be changed later — is a worse way to learn it than being told on the page.
     * The install path checks again on its own account; this is what the screen
     * draws.
     *
     * **[XIV-102] added the price clause and every existing caller keeps its
     * answer**, because every module in this repository is free and a free
     * module's price says so. That is the shape the whole ticket is built to
     * have: a deployment that has priced nothing cannot tell that any of this
     * landed.
     */
    public function isInstallable(): bool
    {
        return !$this->installed
            && !$this->costsMoney()
            && $this->missingRequirements() === [];
    }

    /**
     * Whether obtaining this module involves money changing hands.
     *
     * One question with one answer, asked by the templates, by the controller and
     * by {@see ModuleStore::install()}. It delegates rather than comparing an
     * enum case, so that the day `ModulePricing` grows a fifth case there is one
     * place that decides whether it is the paying kind.
     */
    public function costsMoney(): bool
    {
        return $this->price->costsMoney();
    }

    /**
     * Whether the "request to buy" button is a real offer.
     *
     * The exact mirror of {@see isInstallable()} — same three conditions with the
     * price clause the other way round — and the pair is deliberately exhaustive
     * over a module the customer has not got and can have: it is either free and
     * installable or priced and buyable, never both and never neither. The
     * requirements clause is on this side too, because being sold something that
     * cannot be installed until you have bought something else is a worse
     * discovery after the money conversation than before it.
     */
    public function isBuyable(): bool
    {
        return !$this->installed
            && $this->costsMoney()
            && $this->missingRequirements() === [];
    }

    /**
     * Whether this customer has an outstanding request to buy it.
     *
     * "Outstanding" is not a column anywhere — see
     * {@see ModulePurchaseIntent}, which deliberately carries
     * no status: the module is either installed or it is not, and a request whose
     * module is installed has been answered. So a row plus
     * {@see $installed} is the whole state machine, and there is nothing to keep
     * in step with anything.
     */
    public function hasBeenRequested(): bool
    {
        return $this->requested !== null && !$this->installed;
    }
}

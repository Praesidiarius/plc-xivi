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

namespace App\Registry\Catalog;

use App\Registry\Entity\Module;
use App\Registry\Entity\ModuleState;
use App\Registry\Pricing\ModulePrice;
use Xivi\Core\Module\ModuleBlueprint;

/**
 * One module as the catalogue sees it: what the build knows about it, and what
 * the control plane has decided about it.
 *
 * Both halves are nullable and never both null. No blueprint means a state row
 * naming a module this deploy does not ship — worth showing rather than hiding,
 * since a published module that vanished from the build is a deploy accident and
 * the list is where somebody would notice.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CatalogEntry
{
    public function __construct(
        public string $key,
        public ModuleState $state,
        /**
         * What this deployment charges for it (XIV-101), which for a module
         * nobody has decided about is {@see \App\Registry\Pricing\ModulePricing::Unpriced}
         * and is emphatically not free.
         */
        public ModulePrice $price,
        public ?ModuleBlueprint $blueprint = null,
        /** Null until somebody moves the module off its default state. */
        public ?Module $decision = null,
    ) {
    }

    public function isInBuild(): bool
    {
        return $this->blueprint !== null;
    }

    /**
     * Whether the store (XIV-6) may offer this module — **the whole rule, in one
     * place** (XIV-101).
     *
     * Three conditions, of two different kinds, and every reader wants all three:
     * the platform says the module is finished ({@see ModuleState}, §6.2), this
     * deployment says it is for sale ({@see ModulePrice}), and the deploy
     * actually carries the code. That last one is why a row can say "published"
     * about something nobody could install, which is a deploy accident rather
     * than an offer.
     *
     * Stated here rather than in {@see ModuleCatalog::offeredInStore()} because
     * there are now three readers of it — the store, the operator screen and the
     * introspector (§6.4) — and a rule with three readers and two homes is a rule
     * that will be true in one of them.
     */
    public function isOfferedInStore(): bool
    {
        return $this->isInBuild()
            && $this->state->isOfferedInStore()
            && $this->price->mayBeOffered();
    }
}

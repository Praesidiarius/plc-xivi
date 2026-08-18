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

namespace Xivi\ControlPlane\View;

use App\Registry\Catalog\CatalogEntry;
use App\Registry\Entity\ModuleState;
use App\Registry\Pricing\ModulePricing;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One module as the pricing screen draws it (XIV-101).
 *
 * A view model for the same reason {@see TenantSummary} is one, arrived at from a
 * much less alarming direction: nothing on a `CatalogEntry` is a secret, so this
 * is not a defence against a credential reaching a template. What it is instead
 * is the place the *blueprint* stops.
 *
 * A `ModuleBlueprint` is a whole module description — every field, every
 * collection, every preset, the requirements graph — and handing one to a Twig
 * template invites the page to grow a column off any of it, on a page that
 * deliberately makes one query against one database. It also carries a label that
 * is a translation key, resolved into a tenant's own definitions at install time
 * (XIV-8), and out here there is no tenant: `ListModulesCommand` already worked
 * out that the platform's default language is the only honest thing to render one
 * in, and doing it here means the template never has to know.
 *
 * So the template gets six scalars and two enums, and the awkward question —
 * what language is a module called this in — is answered once, in PHP, where the
 * answer can carry a comment.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleListing
{
    public function __construct(
        public string $key,
        /** The blueprint's label in the platform's default language, or the key when it has none. */
        public string $name,
        public ModuleState $state,
        public ModulePricing $pricing,
        /** The decimal string as stored, so the form redraws exactly what is in the row. */
        public ?string $amount,
        public bool $inBuild,
        /** Published *and* for sale *and* shipped by this build — the whole rule (§6.5). */
        public bool $offeredInStore,
        /** Null until somebody has decided something about this module. */
        public ?\DateTimeImmutable $decidedAt,
    ) {
    }

    public static function of(CatalogEntry $entry, TranslatorInterface $translator): self
    {
        $blueprint = $entry->blueprint;

        return new self(
            $entry->key,
            // The key rather than an em dash for a module that is not in this
            // build: a row whose decision outlived its code is exactly the row
            // somebody is here to find, and it needs a name to be found by.
            $blueprint === null ? $entry->key : $translator->trans($blueprint->label, [], $blueprint->domain()),
            $entry->state,
            $entry->price->pricing,
            $entry->price->amount,
            $entry->isInBuild(),
            $entry->isOfferedInStore(),
            $entry->decision?->getUpdatedAt(),
        );
    }

    /**
     * Whether this module is finished but nobody has said what it costs.
     *
     * The one combination the page exists to make impossible to miss. A published
     * module with no price is not offered in the store, so an operator who
     * published something and stopped is looking at a module their customers
     * cannot see — and the reason is two screens away unless this page says it
     * here.
     */
    public function isPublishedButUnpriced(): bool
    {
        return $this->state->isOfferedInStore() && $this->pricing === ModulePricing::Unpriced;
    }
}

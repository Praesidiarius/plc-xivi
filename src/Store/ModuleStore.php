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

use App\Registry\Catalog\CatalogEntry;
use App\Registry\Catalog\ModuleCatalog;
use App\Tenant\Entity\ModulePurchaseIntent;
use App\Tenant\Entity\User;
use App\Tenant\FollowUp\ModuleFollowUps;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\CollectionBlueprint;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModulePreset;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The store, as something other than three screens (XIV-6).
 *
 * Everything the store knows is composed from two sources it does not own, and
 * the split between them is the thing this ticket had to not get wrong:
 *
 * * **What may be offered** is the control plane's answer, crossed with what this
 *   build actually ships — `ModuleCatalog::offeredInStore()`, which already gets
 *   the subtle half right (a row saying published for a module the deploy does
 *   not carry describes something nobody could install).
 * * **What this customer has**, and therefore what they may install, is read from
 *   **their own database** through `MetadataRepository`, and from nowhere else.
 *
 * **Installing writes only the tenant's database.** Nothing here touches
 * `Tenant::$enabledModules`, and nothing here writes a control-plane row. "Does
 * this customer have module X" is answered from their metadata, which is what
 * makes a tenant-facing store not a hole in the control plane — the split
 * [XIV-60] wants, kept rather than compromised.
 *
 * Presets, requirements and collections all come off the blueprint, so a module
 * added to a future build appears here complete with nothing written in this
 * class about it.
 *
 * **[XIV-102] added a third source and it is not a third place to read from.**
 * What a module costs comes through `ModuleCatalog::price()`, which §6.5 made the
 * one seam onto the `module` row for exactly this reason — the store, the
 * operator screen and the introspector all ask the catalogue rather than each
 * composing their own query. What that buys here is the rule below: **this class
 * will not install a module that costs money.** It writes down that somebody
 * asked instead ({@see PurchaseRequests}), which is [XIV-64]'s separation between
 * asking and happening, applied to a customer rather than to a stranger.
 *
 * ## [XIV-140] split the list in two, and did not add a third source
 *
 * The screen used to ask for one list, `offers()`, and badge the installed ones
 * inside it. It now asks two questions, because a customer arriving at the store
 * has two and they have different answers: {@see self::owned()} is what they
 * have and {@see self::available()} is what they could add. **The second source
 * did not grow a third**: `owned()` reads the same `MetadataRepository` this
 * class has always read, and `available()` reads the same catalogue.
 *
 * What did change is how often each is read. Both used to be asked once per
 * module, which was invisible at six and is a query storm at thirty:
 * `offeredInStore()` inside every requirement check, a `price()` per tile, and a
 * metadata `find()` per tile including one for every module the customer has
 * not got. The reads are now hoisted to one apiece and handed down
 * ({@see self::offerFor()}, {@see self::installedKeys()}), which is the same
 * page composed from the same two sources with the loop on the outside.
 *
 * **Nothing here knows what a category is**, and that is XIV-140's decision
 * rather than an omission. A module belongs to as many trades as sell it, so a
 * category on a module would have to be a list, maintained by hand, that a
 * package already implies. Grouping by trade is [XIV-139]'s packages, and the
 * grouping that ships here is the one the store already knew: theirs, or not
 * theirs yet.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleStore
{
    public function __construct(
        private ModuleCatalog $catalog,
        private ModuleRegistry $registry,
        private MetadataRepository $metadata,
        private ModuleInstaller $installer,
        private TranslatorInterface $translator,
        private ModuleFollowUps $followUps,
        private PurchaseRequests $purchases,
    ) {
    }

    /**
     * What this customer could add: everything the store offers that they have
     * not got, by label, narrowed to whatever they typed (XIV-140).
     *
     * **The installed ones are gone from this list rather than badged inside
     * it**, which is the whole rearrangement of XIV-140 in one line. A module
     * they already have answers a different question from a module they could
     * buy, and mixing the two meant an established customer reading thirty tiles
     * to find the eighteen that were still an offer. They are not hidden: they
     * are in {@see self::owned()}, above this on the page, in their own words.
     *
     * **By label, not by key.** The catalogue orders by module key because two
     * runs have to read the same (§6.2), and a key is a fact about the code. A
     * customer reads labels, and those are translated, so the key order is not
     * even alphabetical on the screen: in German the six modules of this build
     * come out Artikel, Kontakte, Rechnungen, Wissen, Bestellungen, Gutscheine.
     * At six that is a grid; at thirty it is a grid in no order at all, which is
     * a grid you have to read all of.
     *
     * @param string|null $query what the reader typed in the box, or null. See
     *                           {@see self::matches()} for why this is a
     *                           `str_contains` over a handful of strings and not
     *                           the record query layer
     *
     * @return list<StoreOffer>
     */
    public function available(?string $query = null): array
    {
        // The customer's outstanding purchase requests, read once for the page
        // rather than once per tile (XIV-102). Almost always empty, and a query
        // per module for an empty answer is the sort of thing that is invisible
        // at four modules and embarrassing at forty.
        $requested = $this->purchases->byModule();
        $entries = $this->catalog->offeredEntries();
        $installed = $this->installedKeys();

        $available = [];

        foreach ($entries as $entry) {
            $offer = $this->offerFor($entry, $entries, $installed, $requested);

            if ($offer->installed) {
                continue;
            }

            // The collections are in the haystack because they are the words a
            // customer is likely to have in mind: somebody looking for where
            // addresses live should find Contacts, and the module's own label
            // never says the word. Requirements are deliberately not, because a
            // search for Contacts would then return every module that needs one.
            if (!$this->matches($query, $offer->label, ...$offer->collections)) {
                continue;
            }

            $available[] = $offer;
        }

        usort($available, fn (StoreOffer $a, StoreOffer $b): int => $this->compareLabels($a->label, $b->label));

        return $available;
    }

    /**
     * What this customer already has, in their own words (XIV-140).
     *
     * **Read from their definitions, never from the catalogue**, which is the
     * argument on {@see OwnedModule} and the reason the two halves of this
     * screen do not share a type. The short version: a module can leave the
     * store without leaving the customer, so a list built from what the store
     * offers would drop a module they are using that day.
     *
     * @return list<OwnedModule>
     */
    public function owned(?string $query = null): array
    {
        $offered = $this->catalog->offeredEntries();
        $owned = [];

        foreach ($this->metadata->all() as $definition) {
            if (!$this->matches($query, $definition->getLabel())) {
                continue;
            }

            $owned[] = new OwnedModule(
                key: $definition->getKey(),
                label: $definition->getLabel(),
                icon: $definition->getIcon(),
                offered: isset($offered[$definition->getKey()]),
            );
        }

        usort($owned, fn (OwnedModule $a, OwnedModule $b): int => $this->compareLabels($a->label, $b->label));

        return $owned;
    }

    /**
     * One module's page, or null when the store does not offer it.
     *
     * Null rather than a refusal, so the controller answers 404. A module in
     * development is not a secret, but a store page for something nobody can
     * install is a page whose only content is a disappointment.
     */
    public function offer(string $key): ?StoreOffer
    {
        $entries = $this->catalog->offeredEntries();
        $entry = $entries[$key] ?? null;

        return $entry === null
            ? null
            : $this->offerFor($entry, $entries, $this->installedKeys(), $this->purchases->byModule());
    }

    /**
     * Installs one module into this customer's database, at the preset they
     * chose.
     *
     * Every refusal the screen draws is checked again here, because the screen is
     * a courtesy and this is the check — see {@see StoreInstallRefused}. The
     * requirement check in particular is *also* `ModuleInstaller`'s, which refuses
     * on its own account naming what is missing (XIV-23); this one exists so that
     * the message names modules the way the customer reads them rather than by
     * key, and so that the refusal is the same object whether it came from the
     * page or from a retyped post.
     *
     * @param string|null $preset    null takes the blueprint's default, which is what
     *                               `tenant:module:install` does with no `--preset`
     * @param bool        $followUps whether this module's records take follow-ups
     *                               (XIV-80). On by default, and — unlike the
     *                               preset one line up — **not** a permanent
     *                               choice: no table is created per module, so
     *                               this is a boolean on the definition that can
     *                               be turned round afterwards. The wizard asks
     *                               here because it is the natural moment, not
     *                               because it is the last one.
     *
     * @throws StoreInstallRefused
     */
    public function install(StoreOffer $offer, ?string $preset, ?string $locale = null, bool $followUps = true): ModuleDefinition
    {
        if ($offer->installed) {
            throw StoreInstallRefused::alreadyInstalled($offer->label);
        }

        // **The paywall, and it is here rather than in a controller** (XIV-102).
        //
        // A screen that hides the install button is a screen; this is the check,
        // which is the same relationship every other refusal in this method has
        // to the wizard that draws it. It matters more here than for the others,
        // because the thing on the other side of this `if` is a module somebody
        // has not paid for — so a retyped POST, a stale open tab from before an
        // operator set a price, and a future controller that forgets to ask all
        // arrive at the same sentence.
        //
        // It refuses rather than recording a purchase request, and that is
        // deliberate: an install and a request are different acts with different
        // outcomes, and a method that quietly did the second when asked for the
        // first would report success for something that did not happen. The
        // request has its own entry point ({@see PurchaseRequests::record()}) and
        // its own page.
        if ($offer->costsMoney()) {
            throw StoreInstallRefused::costsMoney($offer->label);
        }

        $missing = $offer->missingRequirements();

        if ($missing !== []) {
            throw StoreInstallRefused::requirementsMissing($offer->label, array_map(
                static fn (Requirement $requirement): string => $requirement->label,
                $missing,
            ));
        }

        if ($preset !== null && $offer->blueprint->preset($preset) === null) {
            throw StoreInstallRefused::noSuchPreset($offer->label);
        }

        // The same call the console command makes, with the same arguments. There
        // is deliberately no second install path: a module installed from here
        // and a module installed by `tenant:module:install` are the same module,
        // and the only way to keep that true is for both to go through this.
        $definition = $this->installer->install($offer->blueprint, $preset, $locale);

        // Afterwards rather than as an argument to the installer, and that is the
        // §3 boundary rather than an afterthought: core creates the tables and
        // seeds the definitions, and what a follow-up *is* lives in the
        // application, next to the users one names. Only the off case writes
        // anything — the entity's own default is on, so the ordinary install
        // touches nothing.
        if (!$followUps) {
            $this->followUps->set($definition, false);
        }

        return $definition;
    }

    /**
     * @param CatalogEntry                        $entry     the module being drawn, price and all
     * @param array<string, CatalogEntry>         $offered   everything the store offers, so that
     *                                                       neither this nor {@see self::requirementsOf()}
     *                                                       goes back to the control plane per tile (XIV-140)
     * @param array<string, true>                 $installed the keys this customer has, from
     *                                                       {@see self::installedKeys()}
     * @param array<string, ModulePurchaseIntent> $requested this customer's
     *                                                       outstanding purchase requests, keyed by module
     */
    private function offerFor(CatalogEntry $entry, array $offered, array $installed, array $requested): StoreOffer
    {
        // Guaranteed by `offeredEntries()`, which only ever returns entries the
        // build carries, and said again here because the type system cannot read
        // that guarantee off the array.
        $blueprint = $entry->blueprint ?? throw new \LogicException(sprintf(
            'The store was handed "%s", which this build does not carry.',
            $entry->key,
        ));

        return new StoreOffer(
            blueprint: $blueprint,
            label: $this->label($blueprint->label, $blueprint),
            installed: isset($installed[$blueprint->key]),
            // Off the entry, which the catalogue already filled from the same
            // `module` row §6.5 made the single seam onto. Reading it back with
            // `ModuleCatalog::price()` would be that seam used one query per
            // tile, which is the same answer bought thirty times.
            price: $entry->price,
            requirements: $this->requirementsOf($blueprint, $offered, $installed),
            presets: $this->presetsOf($blueprint),
            collections: array_map(
                fn (CollectionBlueprint $collection): string => $this->label($collection->label, $blueprint),
                $blueprint->collections,
            ),
            requested: $requested[$blueprint->key] ?? null,
        );
    }

    /**
     * Which modules this customer has, as a set, read in one query (XIV-140).
     *
     * `MetadataRepository::find()` per module was one statement per module and,
     * worse, one statement per module they have **not** got: the per-key cache
     * keeps a miss so it is asked once, but once each is still thirty questions
     * where `all()` is one. The same read fills {@see self::owned()}, so the page
     * pays for it either way.
     *
     * @return array<string, true>
     */
    private function installedKeys(): array
    {
        $keys = [];

        foreach ($this->metadata->all() as $definition) {
            $keys[$definition->getKey()] = true;
        }

        return $keys;
    }

    /**
     * Whether what somebody typed in the search box matches any of these
     * strings (XIV-140).
     *
     * **A `str_contains` over a handful of labels, and that is a decision rather
     * than a shortcut.** §3.2 settled that the store is designed for a curated
     * set and may assume it: no unbounded catalogue, no search index it does not
     * need. Thirty modules are already in memory by the time this runs, because
     * the page is going to draw all of them, so the cheapest correct filter is
     * the one that reads them.
     *
     * The record query layer was the other candidate and is the wrong tool by a
     * wide margin. `Operator::Contains` builds SQL against a customer's records
     * through their field definitions; a module in the store is neither a record
     * nor a field nor even a row in the tenant's database, so using it would
     * mean inventing a fake shape to query. That is the second implementation
     * this ticket was told not to build.
     *
     * Case-folded on both sides and nothing else: no stemming, no fuzzy match,
     * no ranking. Somebody typing "rechn" is looking for Rechnungen and a
     * substring finds it; somebody typing something that matches nothing is
     * told so, which is a better answer than a page guessing at what they meant.
     */
    private function matches(?string $query, string ...$text): bool
    {
        $needle = mb_strtolower(trim((string) $query));

        if ($needle === '') {
            return true;
        }

        foreach ($text as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two labels in the order the person reading them would put them (XIV-140).
     *
     * Through `Collator` rather than `strcmp`, for the same reason
     * `CurrencyFieldType` formats through `NumberFormatter`: the languages this
     * ships in are German, French and Italian, and a byte comparison files Ärzte
     * after Zimmer and Ãle wherever its first byte lands. A list somebody cannot
     * predict the order of is a list they have to read all of, which is the
     * whole failure this ticket is about.
     *
     * `Locale::getDefault()` is the request's locale: Symfony's `LocaleListener`
     * sets it from the request, which is why the field types already read it
     * rather than being handed one.
     */
    private function compareLabels(string $a, string $b): int
    {
        $collator = new \Collator(\Locale::getDefault());

        return $collator->compare($a, $b) ?: 0;
    }

    /**
     * Writes down that this customer wants a module that costs money, and
     * installs nothing (XIV-102).
     *
     * **A separate method from {@see install()} on purpose**, and the two never
     * call each other. §8.15 has the argument; the short version is that the
     * whole ticket is the difference between asking and happening, and one method
     * with a branch in it is one refactor from erasing that difference. This one
     * has no `ModuleInstaller` anywhere behind it — {@see PurchaseRequests} takes
     * a repository, the deployment's currency and an entity manager, which is the
     * same shape [XIV-64] gave the signup intake and the same thing
     * `SignupEndpointTest` proves about it.
     *
     * The offer is re-checked by the service on its own account, so a stale page
     * is refused with a sentence rather than stored.
     *
     * @throws PurchaseRefused
     */
    public function requestPurchase(StoreOffer $offer, ?User $requester): ModulePurchaseIntent
    {
        return $this->purchases->record($offer, $requester);
    }

    /**
     * What this module needs, each answered against this customer's own
     * definitions.
     *
     * `uses` is deliberately not listed: those are modules this one works better
     * with and works without, and the parts needing them are simply not offered
     * (see ModuleBlueprint). Showing them here would read as a requirement.
     *
     * @param array<string, CatalogEntry> $offered   handed in rather than fetched, because
     *                                               this used to ask the catalogue once per
     *                                               module and the catalogue asks the database
     *                                               (XIV-140)
     * @param array<string, true>         $installed the keys this customer has
     *
     * @return list<Requirement>
     */
    private function requirementsOf(ModuleBlueprint $blueprint, array $offered, array $installed): array
    {
        return array_map(function (string $key) use ($offered, $installed): Requirement {
            // A requirement this build does not ship at all can still be named:
            // its key is the only label there is, which is visibly a key and
            // therefore visibly wrong, rather than quietly blank.
            $required = $this->registry->has($key) ? $this->registry->get($key) : null;

            return new Requirement(
                key: $key,
                label: $required === null ? $key : $this->label($required->label, $required),
                installed: isset($installed[$key]),
                offered: isset($offered[$key]),
            );
        }, $blueprint->requires);
    }

    /**
     * The presets, with the fields each contains resolved to labels.
     *
     * @return list<PresetOffer>
     */
    private function presetsOf(ModuleBlueprint $blueprint): array
    {
        return array_map(function (ModulePreset $preset) use ($blueprint): PresetOffer {
            // The blueprint's order, not the preset's: the module author decided
            // what sits next to what, and a preset chooses which of those to take
            // rather than rearranging them — the same rule ModuleInstaller
            // applies when it actually installs them, so the list somebody reads
            // is the order they will get.
            $fields = array_values(array_filter(
                $blueprint->fields,
                static fn (FieldBlueprint $field): bool => \in_array($field->key, $preset->fields, true),
            ));

            return new PresetOffer(
                key: $preset->key,
                label: $this->label($preset->label, $blueprint),
                description: $this->label($preset->description, $blueprint),
                fields: array_map(
                    fn (FieldBlueprint $field): string => $this->label($field->label, $blueprint),
                    $fields,
                ),
                isDefault: $preset->key === $blueprint->defaultPreset,
            );
        }, $blueprint->presets);
    }

    /**
     * A label out of the module's own catalogue, in the language being read.
     *
     * Read at render time rather than seeded, which is the opposite of what
     * `ModuleInstaller` does with the very same strings and is right for the
     * opposite reason: the installer is writing the customer's data, which they
     * may then rename, and this is a shop window describing something they do not
     * have yet. There is nothing here for them to have renamed.
     *
     * A key with no entry falls back to itself, so a module shipping no catalogue
     * appears with its keys showing — visibly wrong rather than quietly empty.
     */
    private function label(string $key, ModuleBlueprint $blueprint): string
    {
        return $this->translator->trans($key, [], $blueprint->domain());
    }
}

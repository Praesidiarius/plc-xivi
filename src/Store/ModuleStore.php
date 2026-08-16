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

use App\ControlPlane\Module\ModuleCatalog;
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
    ) {
    }

    /**
     * Every module this build offers, in the catalogue's order.
     *
     * @return list<StoreOffer>
     */
    public function offers(): array
    {
        return array_values(array_map(
            fn (ModuleBlueprint $blueprint): StoreOffer => $this->offerFor($blueprint),
            $this->catalog->offeredInStore(),
        ));
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
        $blueprint = $this->catalog->offeredInStore()[$key] ?? null;

        return $blueprint === null ? null : $this->offerFor($blueprint);
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
     * @param string|null $preset null takes the blueprint's default, which is what
     *                            `tenant:module:install` does with no `--preset`
     *
     * @throws StoreInstallRefused
     */
    public function install(StoreOffer $offer, ?string $preset, ?string $locale = null): ModuleDefinition
    {
        if ($offer->installed) {
            throw StoreInstallRefused::alreadyInstalled($offer->label);
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
        return $this->installer->install($offer->blueprint, $preset, $locale);
    }

    private function offerFor(ModuleBlueprint $blueprint): StoreOffer
    {
        return new StoreOffer(
            blueprint: $blueprint,
            label: $this->label($blueprint->label, $blueprint),
            installed: $this->metadata->find($blueprint->key) !== null,
            requirements: $this->requirementsOf($blueprint),
            presets: $this->presetsOf($blueprint),
            collections: array_map(
                fn (CollectionBlueprint $collection): string => $this->label($collection->label, $blueprint),
                $blueprint->collections,
            ),
        );
    }

    /**
     * What this module needs, each answered against this customer's own
     * definitions.
     *
     * `uses` is deliberately not listed: those are modules this one works better
     * with and works without, and the parts needing them are simply not offered
     * (see ModuleBlueprint). Showing them here would read as a requirement.
     *
     * @return list<Requirement>
     */
    private function requirementsOf(ModuleBlueprint $blueprint): array
    {
        $offered = $this->catalog->offeredInStore();

        return array_map(function (string $key) use ($offered): Requirement {
            // A requirement this build does not ship at all can still be named:
            // its key is the only label there is, which is visibly a key and
            // therefore visibly wrong, rather than quietly blank.
            $required = $this->registry->has($key) ? $this->registry->get($key) : null;

            return new Requirement(
                key: $key,
                label: $required === null ? $key : $this->label($required->label, $required),
                installed: $this->metadata->find($key) !== null,
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

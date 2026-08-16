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

use Xivi\Core\Module\ModuleBlueprint;

/**
 * One module as the store shows it: what it is, whether this customer has it,
 * and whether they could (XIV-6).
 *
 * Two facts about the same module from two different places, which is the whole
 * reason this exists rather than a template reaching for both. What the build
 * offers comes from the control plane crossed with the registry
 * ({@see \App\ControlPlane\Module\ModuleCatalog}); whether this customer has it,
 * and whether they have what it needs, comes from their **own** database and
 * nowhere else. Joining them in a value object is what keeps the controller from
 * being the place that knows the difference.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class StoreOffer
{
    /**
     * @param list<Requirement> $requirements what this module needs, each saying
     *                                        whether the customer has it
     * @param list<PresetOffer> $presets      empty for a module that ships none,
     *                                        which installs every field it has
     * @param list<string>      $collections  labels of the child tables installing
     *                                        creates whatever preset is chosen (§6.1)
     */
    public function __construct(
        public ModuleBlueprint $blueprint,
        public string $label,
        public bool $installed,
        public array $requirements,
        public array $presets = [],
        public array $collections = [],
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
     */
    public function isInstallable(): bool
    {
        return !$this->installed && $this->missingRequirements() === [];
    }
}

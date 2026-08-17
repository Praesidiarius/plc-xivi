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
        public ?ModuleBlueprint $blueprint = null,
        /** Null until somebody moves the module off its default state. */
        public ?Module $decision = null,
    ) {
    }

    public function isInBuild(): bool
    {
        return $this->blueprint !== null;
    }
}

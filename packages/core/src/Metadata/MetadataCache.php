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

namespace Xivi\Core\Metadata;

use Xivi\Core\Entity\ModuleDefinition;

/**
 * The definitions already read for whichever tenant is current (XIV-53).
 *
 * **Why this is worth having.** Reading a module's definitions is one fetch-joined
 * query, and a page reads them constantly — every field type asks for its own
 * shape, every reference asks for its target's, every reverse-link group asks
 * again. Measured (XIV-46), that made metadata the single largest source of
 * queries on every page looked at: thirteen of twenty-five on a record list,
 * more in absolute terms than the two N+1s that were being hunted.
 *
 * **Why it was not here already, and what makes it safe now.** A cache of one
 * customer's field definitions handed to another is the worst bug this system
 * could have, and it would not look like a bug — it would look like the wrong
 * labels on somebody else's data (§7.4). So the lifetime is the thing to get
 * right, and it is deliberately the shortest one that helps:
 *
 * - **A web request is a process.** FrankenPHP runs without worker mode on
 *   purpose, so nothing here outlives the request that filled it, and the common
 *   case is safe by construction rather than by discipline.
 * - **A console command is not.** `tenant:migrate` walks every tenant in one
 *   process, and that is exactly where a stale entry would be served to the next
 *   customer. So `TenantSwitcher` empties this whenever the context moves — the
 *   same moment it drops the entity manager's identity map and closes the
 *   connection, because they are the same fact about the same boundary.
 *
 * There is no tenant *key* here on purpose. Keying by tenant would make it look
 * safe to keep entries across a switch, and the objects are Doctrine entities
 * bound to a connection that has just been closed — a definition kept across the
 * boundary would load its fields on whatever connection is current, which is the
 * hazard rather than the fix. Empty it, and read again.
 *
 * **Writers empty it too.** Adding a field or installing a module changes what
 * these queries would return, and a page that showed a stale shape immediately
 * after somebody edited it would look like the edit had failed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MetadataCache
{
    /**
     * Definitions by module key, including the misses.
     *
     * `null` is a real answer worth keeping: `isInstalled()` asks about modules a
     * customer does not have, and remembering that is what stops a page repeating
     * the same negative query for every field that mentions it.
     *
     * @var array<string, ModuleDefinition|null>
     */
    private array $definitions = [];

    /** @var list<ModuleDefinition>|null */
    private ?array $all = null;

    /**
     * One module's definitions, reading them if this is the first time.
     *
     * @param callable(): (ModuleDefinition|null) $load
     */
    public function definition(string $moduleKey, callable $load): ?ModuleDefinition
    {
        // `array_key_exists`, not `??`: a module that is not installed caches as
        // null, and the point of keeping that is to not ask twice.
        if (!\array_key_exists($moduleKey, $this->definitions)) {
            $this->definitions[$moduleKey] = $load();
        }

        return $this->definitions[$moduleKey];
    }

    /**
     * Every installed module.
     *
     * Fills the per-key entries as well, since it has just read them all —
     * `linkedTo()` asks for everything and then asks about each one in turn.
     *
     * @param callable(): list<ModuleDefinition> $load
     *
     * @return list<ModuleDefinition>
     */
    public function everything(callable $load): array
    {
        if ($this->all === null) {
            $this->all = $load();

            foreach ($this->all as $definition) {
                $this->definitions[$definition->getKey()] = $definition;
            }
        }

        return $this->all;
    }

    /**
     * Forget everything.
     *
     * Called when the tenant moves and when definitions change. Cheap, and
     * correct to call more often than strictly needed — the failure this guards
     * is silent, and re-reading is one query.
     */
    public function clear(): void
    {
        $this->definitions = [];
        $this->all = null;
    }
}

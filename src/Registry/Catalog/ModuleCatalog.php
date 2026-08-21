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
use App\Registry\Repository\ModuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The two halves of "which modules are there, and how far along is each" (XIV-7).
 *
 * The build says what exists — `ModuleRegistry`, filled from the tagged providers
 * a deploy happens to contain. The control plane says what state each is in, in
 * rows that outlive any one deploy. Neither half is authoritative on the other's
 * question, and this is the one place that knows it, so nothing else has to
 * remember that a state row can name a module this build no longer ships, or that
 * a module nobody has decided about is in development.
 *
 * It lives in the application rather than in core, because core is handed a
 * tenant's connection and must never reach for the control plane (§3, §4) — and
 * a module's state is the platform's answer, identical for every tenant.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleCatalog
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleRepository $modules,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** A module nobody has decided about is in development — see {@see Module}. */
    public function state(string $key): ModuleState
    {
        return $this->modules->findOneByKey($key)?->getState() ?? ModuleState::Development;
    }

    /**
     * What this deployment charges for a module (XIV-101).
     *
     * **The one seam.** [XIV-102] builds what a customer sees in the store and
     * has to price it from somewhere; that somewhere is here, beside
     * {@see self::state()} and {@see self::offeredInStore()}, rather than a
     * second service reading the same table. The catalogue is already the one
     * place the build's half and the control plane's half are joined, and a price
     * is the control plane's half.
     *
     * A module with no row is **unpriced**, not free. So is a module whose row
     * exists because somebody published it and never touched the price — which is
     * the interesting case, because it is the one where a default of "free" would
     * have quietly given the module away.
     */
    public function price(string $key): ModulePrice
    {
        return $this->modules->findOneByKey($key)?->getPrice() ?? ModulePrice::unpriced();
    }

    /**
     * Everything worth listing: every module in this build, plus any state row
     * naming one that is not.
     *
     * Ordered by key so two runs read the same, and so the build's modules and the
     * leftovers interleave rather than the leftovers being a footnote nobody scrolls to.
     *
     * @return list<CatalogEntry>
     */
    public function entries(): array
    {
        $rows = $this->modules->allByKey();
        $blueprints = $this->registry->all();

        $keys = array_unique([...array_keys($blueprints), ...array_keys($rows)]);
        sort($keys);

        return array_map(
            static fn (string $key): CatalogEntry => new CatalogEntry(
                $key,
                ($rows[$key] ?? null)?->getState() ?? ModuleState::Development,
                ($rows[$key] ?? null)?->getPrice() ?? ModulePrice::unpriced(),
                $blueprints[$key] ?? null,
                $rows[$key] ?? null,
            ),
            $keys,
        );
    }

    /**
     * The modules the store may offer (XIV-6), keyed by module key.
     *
     * Published, priced *and* present in this build — the rule itself lives on
     * {@see CatalogEntry::isOfferedInStore()}, because since [XIV-101] it has
     * three clauses and three readers and was previously stated in two places.
     *
     * **[XIV-101] narrowed what this returns and that is the point of the
     * ticket.** A published module nobody has priced is no longer offered, where
     * before this column existed every published module was. The alternative —
     * treating a null price as free — is the exact failure the ticket names:
     * a module ships at zero on the day somebody adds the column, and nothing
     * anywhere says it happened. So the store falls silent about a module until
     * an operator has said what it costs, `module:list` and the operator screen
     * both say which modules are in that state, and `module:state` says it again
     * at the moment somebody publishes one.
     *
     * Built from {@see self::entries()} rather than from a second loop over the
     * registry, which also means the order here is the catalogue's — by key —
     * instead of whatever order the tagged providers happened to be compiled in.
     *
     * @return array<string, ModuleBlueprint>
     */
    public function offeredInStore(): array
    {
        $offered = [];

        foreach ($this->offeredEntries() as $key => $entry) {
            // The null check is `isInBuild()` said again in the one form the
            // type system understands; the rule itself is on the entry.
            if ($entry->blueprint !== null) {
                $offered[$key] = $entry->blueprint;
            }
        }

        return $offered;
    }

    /**
     * The same modules, as whole entries rather than as blueprints alone
     * (XIV-140).
     *
     * {@see self::offeredInStore()} throws away everything the control plane
     * said, which is fine when the caller only wants to know what exists and
     * costs the caller a second visit when it wants the price as well. The store
     * wants both for every tile it draws, and the version of that which reads
     * one blueprint at a time was quietly O(n) in control-plane queries: one
     * `allByKey()` for the list, another inside every module's requirement
     * check, and a `findOneByKey()` per module for its price. Six modules made
     * that about twenty statements and nobody noticed; thirty makes it ninety,
     * all of them re-reading the same handful of rows.
     *
     * So this is the shape the store actually asks for, and it is a return type
     * rather than a mechanism: {@see self::entries()} already built these
     * objects and already carries the price on each. Nothing here decides
     * anything new, and {@see CatalogEntry::isOfferedInStore()} is still the one
     * place the rule lives.
     *
     * @return array<string, CatalogEntry>
     */
    public function offeredEntries(): array
    {
        $offered = [];

        foreach ($this->entries() as $entry) {
            if ($entry->isOfferedInStore()) {
                $offered[$entry->key] = $entry;
            }
        }

        return $offered;
    }

    /**
     * Moves a module to a state, writing the row if this is the first decision
     * anybody has made about it.
     *
     * Refuses a key this build does not ship. The alternative is letting a typo
     * create a row that silently does nothing, and the state of a module that
     * does not exist is not a thing the platform can hold an opinion about.
     *
     * @throws \InvalidArgumentException when no such module is in this build
     */
    public function moveTo(string $key, ModuleState $state): Module
    {
        if (!$this->registry->has($key)) {
            throw new \InvalidArgumentException(sprintf(
                'No module "%s" in this build. Available: %s.',
                $key,
                implode(', ', array_keys($this->registry->all())) ?: 'none',
            ));
        }

        $module = $this->modules->findOneByKey($key);

        if ($module === null) {
            $module = new Module($key, $state);
            $this->entityManager->persist($module);
        } else {
            $module->setState($state);
        }

        $this->entityManager->flush();

        return $module;
    }

    /**
     * Sets what this deployment charges for a module (XIV-101), writing the row
     * if this is the first decision anybody has made about it.
     *
     * ## The [XIV-96] tension, resolved rather than noticed
     *
     * Reading a price and setting one land on opposite sides of the two-image
     * split (§4.4), and this method is the visible seam. `App\Registry` stays in
     * `src/` because a customer's request needs the registry to be served at all,
     * so the customer-facing image contains this class and every read above it.
     * It also contains this method — and cannot use it, because §4.4 grants that
     * image's database role `SELECT` on the registry tables and nothing else. An
     * `UPDATE module` arriving from the process facing the internet is refused by
     * PostgreSQL, not by a routing decision or by care.
     *
     * That is exactly the arrangement {@see self::moveTo()} has had since
     * [XIV-7], and the check §4.4 documents is the same one: **every caller is in
     * `packages/control-plane`** — the operator screen and `module:price` — and
     * the package is not in the customer-facing image at all. So the method that
     * writes is present and unreachable, which is a weaker guarantee than absence
     * and is not the guarantee being relied on. The grant is.
     *
     * Splitting the writer out into the package instead was weighed and rejected:
     * it would put half of one entity's invariants in `src/` and half in a bundle,
     * so `ModulePrice`'s rules would be enforced by whichever half a future caller
     * happened to go through. One writer, one place, and a database role that
     * says no to the image that should never reach it.
     *
     * ## What this does not do
     *
     * It writes two columns of one control-plane row. It does not install
     * anything, uninstall anything, or touch any tenant's database — a price is
     * what a module costs *from now on*, and §6.2 already settled the same point
     * for state: a decision here says what may be obtained from the store, never
     * what is taken away from somebody who already has it. `ModulePriceTest`
     * proves it against a real tenant rather than asserting it here.
     *
     * A price that has not changed is not written, so `updated_at` keeps meaning
     * "when this module's price last moved" rather than "when somebody last
     * pressed save" — the same care {@see Module::setState()} takes with its row.
     *
     * @throws \InvalidArgumentException when no such module is in this build
     */
    public function priceAt(string $key, ModulePrice $price): Module
    {
        if (!$this->registry->has($key)) {
            throw new \InvalidArgumentException(sprintf(
                'No module "%s" in this build. Available: %s.',
                $key,
                implode(', ', array_keys($this->registry->all())) ?: 'none',
            ));
        }

        $module = $this->modules->findOneByKey($key);

        if ($module === null) {
            $module = new Module($key);
            $module->setPrice($price);
            $this->entityManager->persist($module);
            $this->entityManager->flush();

            return $module;
        }

        if ($module->getPrice()->equals($price)) {
            return $module;
        }

        $module->setPrice($price);
        $this->entityManager->flush();

        return $module;
    }
}

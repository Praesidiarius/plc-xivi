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

namespace App\ControlPlane\Module;

use App\ControlPlane\Entity\Module;
use App\ControlPlane\Entity\ModuleState;
use App\ControlPlane\Repository\ModuleRepository;
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
                $blueprints[$key] ?? null,
                $rows[$key] ?? null,
            ),
            $keys,
        );
    }

    /**
     * The modules the store may offer (XIV-6), keyed by module key.
     *
     * Published *and* present in this build: a row saying published for something
     * the deploy does not ship describes a module nobody could install.
     *
     * @return array<string, ModuleBlueprint>
     */
    public function offeredInStore(): array
    {
        $rows = $this->modules->allByKey();
        $offered = [];

        foreach ($this->registry->all() as $key => $blueprint) {
            $state = ($rows[$key] ?? null)?->getState() ?? ModuleState::Development;

            if ($state->isOfferedInStore()) {
                $offered[$key] = $blueprint;
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
}

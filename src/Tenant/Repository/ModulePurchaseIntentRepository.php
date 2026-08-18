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

namespace App\Tenant\Repository;

use App\Tenant\Entity\ModulePurchaseIntent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The purchase requests this customer has outstanding (XIV-102).
 *
 * Resolves through the `tenant` manager, like every repository in this
 * namespace, so an intent is only ever read out of the database of the customer
 * being served (§8.1). That is not a nicety here: the collector that shows an
 * operator these rows ({@see \Xivi\ControlPlane\Purchase\PurchaseIntentCollector})
 * calls {@see self::allByModule()} once per tenant *inside* a
 * `TenantSwitcher::runFor()`, so the same method answers about a different
 * customer each time round the loop and answers about none at all outside one.
 *
 * @extends ServiceEntityRepository<ModulePurchaseIntent>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class ModulePurchaseIntentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModulePurchaseIntent::class);
    }

    /**
     * This customer's request for one module, or null when they have not asked.
     *
     * The unique index makes "or null" the whole of the answer — there is never a
     * second row to choose between, which is why asking again rewrites rather
     * than appends ({@see ModulePurchaseIntent::reissue()}).
     */
    public function findOneByModule(string $moduleKey): ?ModulePurchaseIntent
    {
        return $this->findOneBy(['moduleKey' => $moduleKey]);
    }

    /**
     * Everything this customer has asked for, keyed by module.
     *
     * Keyed rather than a list because both readers — the store, deciding what to
     * say on each tile, and the collector, matching a request against what is
     * installed — want to look one module up rather than to iterate. Ordered by
     * key underneath so two runs of the collector produce the same order and a
     * diff of its report is about what changed.
     *
     * @return array<string, ModulePurchaseIntent>
     */
    public function allByModule(): array
    {
        $intents = [];

        foreach ($this->findBy([], ['moduleKey' => 'ASC']) as $intent) {
            $intents[$intent->getModuleKey()] = $intent;
        }

        return $intents;
    }
}

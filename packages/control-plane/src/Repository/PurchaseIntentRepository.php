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

namespace Xivi\ControlPlane\Repository;

use App\Registry\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Xivi\ControlPlane\Entity\PurchaseIntent;

/**
 * @extends ServiceEntityRepository<PurchaseIntent>
 *
 * The collected purchase requests, read from the control-plane database and from
 * nowhere else (XIV-102).
 *
 * The same property {@see TenantUsageRepository} is built around: nothing here
 * opens a tenant connection, because everything here is a copy the collector
 * already made. The screen that lists these rows is one request against one
 * database, which is what lets `PurchaseIntentTest` assert the same thing
 * `TenantListTest` does.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class PurchaseIntentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseIntent::class);
    }

    /**
     * Everything one customer has been found asking for, keyed by module.
     *
     * What the collector needs in order to tell three cases apart in one pass:
     * a request it has seen before and is updating, one it has not seen and is
     * inserting, and a row here whose request has gone from the customer's
     * database and should go from this one too.
     *
     * @return array<string, PurchaseIntent>
     */
    public function forTenantByModule(Tenant $tenant): array
    {
        $byModule = [];

        foreach ($this->findBy(['tenant' => $tenant]) as $intent) {
            $byModule[$intent->getModuleKey()] = $intent;
        }

        return $byModule;
    }

    /**
     * Every outstanding request across every customer, for the operator's screen.
     *
     * **Ordered by what is still owed, oldest first**, which is the order
     * somebody working through a queue reads it in: an unfulfilled request from
     * March is the row that has gone wrong, and a list sorted by tenant name
     * would bury it under whatever alphabet the customers happen to have.
     * Fulfilled rows sink to the bottom rather than being filtered out here —
     * the page decides what to draw, and a repository that hid them would make
     * "did we ever do this one" a question with no screen behind it.
     *
     * The tenant is fetch-joined because every row needs it and fifty lazy loads
     * on one page is the shape this whole feature exists to avoid. One query,
     * one database.
     *
     * @return list<PurchaseIntent>
     */
    public function allOutstandingFirst(): array
    {
        /** @var list<PurchaseIntent> $rows */
        $rows = $this->createQueryBuilder('p')
            ->innerJoin('p.tenant', 't')
            ->addSelect('t')
            ->orderBy('p.installed', 'ASC')
            ->addOrderBy('p.firstRequestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}

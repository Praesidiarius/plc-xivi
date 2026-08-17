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

namespace App\ControlPlane\Repository;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Entity\TenantUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantUsage>
 *
 * Reads that all land in the control-plane database (XIV-59). Nothing in here
 * opens a tenant connection, and that is the property the tenant list is built
 * on: what a customer uses is *read* from these rows and *written* by the
 * collector, and only the collector ever goes near a customer's database.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class TenantUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantUsage::class);
    }

    public function findOneForTenant(Tenant $tenant): ?TenantUsage
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /**
     * Every collection there is, keyed by the tenant it is about.
     *
     * **One query for the whole page, and a map rather than an association.** The
     * obvious modelling — a `OneToOne` on `Tenant` with `mappedBy` — was tried and
     * rejected: Doctrine cannot lazily load the inverse side of a nullable
     * one-to-one, because a proxy cannot stand in for null, so every `Tenant`
     * hydrated anywhere in the application would fetch its usage row whether or
     * not anybody asked. `tenant:list`, provisioning, the tenancy listener on
     * every single request — all of them would pay a second query for a figure
     * they have no use for. The association points one way only for that reason,
     * and the page pays for exactly what it reads: two queries against one
     * database, which is still one request, one database (§8.10).
     *
     * A tenant with no collection is simply missing from the map, and the caller
     * reads that absence as *never collected* — see {@see TenantUsage}.
     *
     * Keyed by slug rather than by id, because the slug is what a `Tenant`
     * certainly has: an id is null until the row is written, so keying by it
     * would make every caller answer a question — what if this tenant has not
     * been persisted? — that cannot arise for rows that were just read out of the
     * registry.
     *
     * @return array<string, TenantUsage> tenant slug => its latest collection
     */
    public function bySlug(): array
    {
        $bySlug = [];

        /** @var list<TenantUsage> $rows */
        $rows = $this->createQueryBuilder('u')
            ->innerJoin('u.tenant', 't')
            ->addSelect('t')
            ->getQuery()
            ->getResult();

        foreach ($rows as $usage) {
            $bySlug[$usage->getTenant()->getSlug()] = $usage;
        }

        return $bySlug;
    }
}

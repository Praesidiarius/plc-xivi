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
use App\ControlPlane\Entity\TenantDomain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    /**
     * @param string $hostname lowercased, port-less hostname
     */
    public function findOneByHostname(string $hostname): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.domains', 'd')
            ->addSelect('d')
            ->andWhere('d.hostname = :hostname')
            ->setParameter('hostname', $hostname)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneBySlug(string $slug): ?Tenant
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return list<Tenant>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every tenant, with its hostnames already in hand (XIV-58).
     *
     * `findAllOrdered()` above serves `tenant:list`, which prints the hostnames
     * too and pays for them one lazy load per row — invisible at a console, where
     * the command runs once and nobody is watching a latency budget. A page is a
     * different setting, so this asks for the join.
     *
     * **A `leftJoin`, not the `innerJoin` that `findOneByHostname()` uses**, and
     * the difference is the entire reason this is a separate method rather than a
     * flag on that one. An inner join drops a tenant with no domains, and a tenant
     * with no domains is exactly the wreckage this page is for: provisioning
     * writes the row before it routes any hostname to it, so a run that died in
     * between leaves precisely that. Silently omitting it would make the list
     * *least* trustworthy in the one case somebody is reading it for.
     *
     * The ordering is by name and stops there. Which rows a reader should see
     * first is {@see \App\ControlPlane\Entity\TenantStatus::attentionRank()}'s
     * answer, and it is applied in PHP rather than translated into a `CASE` in the
     * `ORDER BY` — see `TenantListController` for why that is not laziness.
     *
     * @return list<Tenant>
     */
    public function findAllWithDomains(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.domains', 'd')
            ->addSelect('d')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hostnameIsTaken(string $hostname): bool
    {
        return $this->getEntityManager()
            ->createQuery('SELECT COUNT(d.id) FROM ' . TenantDomain::class . ' d WHERE d.hostname = :hostname')
            ->setParameter('hostname', $hostname)
            ->getSingleScalarResult() > 0;
    }
}

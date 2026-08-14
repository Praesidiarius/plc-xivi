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

    public function hostnameIsTaken(string $hostname): bool
    {
        return $this->getEntityManager()
            ->createQuery('SELECT COUNT(d.id) FROM ' . TenantDomain::class . ' d WHERE d.hostname = :hostname')
            ->setParameter('hostname', $hostname)
            ->getSingleScalarResult() > 0;
    }
}

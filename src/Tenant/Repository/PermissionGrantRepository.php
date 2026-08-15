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

use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PermissionGrant>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class PermissionGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionGrant::class);
    }

    /**
     * Every grant that applies to one person: their groups' and their own.
     *
     * One query rather than walking the groups and asking each in turn, because
     * this runs on every page that shows a module and an N+1 there is a cost paid
     * forever. The union happens in SQL and the fold happens in the resolver.
     *
     * @return list<PermissionGrant>
     */
    public function findForUser(User $user): array
    {
        $groupIds = [];
        foreach ($user->getPermissionGroups() as $group) {
            $groupIds[] = $group->getId();
        }

        $qb = $this->createQueryBuilder('g')
            ->where('g.holderUser = :user')
            ->setParameter('user', $user);

        // An empty IN () is not valid SQL, and a person in no groups is the
        // ordinary case rather than an edge one.
        if ($groupIds !== []) {
            $qb->orWhere('g.holderGroup IN (:groups)')
                ->setParameter('groups', $groupIds);
        }

        /** @var list<PermissionGrant> $grants */
        $grants = $qb->getQuery()->getResult();

        return $grants;
    }
}

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

use App\Tenant\Entity\PermissionGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PermissionGroup>
 *
 * Resolves through the `tenant` manager, so a group is only ever read from the
 * database of the customer being served (§8.1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class PermissionGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionGroup::class);
    }

    public function findOneByKey(string $key): ?PermissionGroup
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return list<PermissionGroup> */
    public function all(): array
    {
        return $this->findBy([], ['label' => 'ASC']);
    }
}

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

use App\Tenant\Entity\TenantProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantProfile>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class TenantProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantProfile::class);
    }

    /**
     * This installation's profile.
     *
     * The migration writes the row for every tenant, so the normal path is a
     * primary-key read. A fresh unsaved profile is returned if it is somehow
     * missing anyway — a settings page that 500s because a row was never inserted
     * is a worse failure than one showing empty fields, and saving it writes the
     * row at the id the entity fixes.
     */
    public function current(): TenantProfile
    {
        return $this->find(TenantProfile::ID) ?? new TenantProfile();
    }
}

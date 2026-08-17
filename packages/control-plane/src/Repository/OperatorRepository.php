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

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Xivi\ControlPlane\Entity\Operator;

/**
 * Operators, out of the control-plane database (XIV-57).
 *
 * The mirror image of `App\Tenant\Repository\UserRepository`, and the difference
 * is the only interesting thing about it: that one is reached through the tenant
 * entity manager and answers "who is this email *here*", while this one is
 * reached through the control plane's — the default manager — and answers a
 * question that has nothing to do with which customer is being served.
 *
 * @extends ServiceEntityRepository<Operator>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class OperatorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Operator::class);
    }

    public function findOneByEmail(string $email): ?Operator
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }
}

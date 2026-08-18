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

    /**
     * Everybody who can sign in to this installation, revoked ones included
     * (XIV-92).
     *
     * Revoked rows are in here on purpose: "who can sign in" is the question
     * `control:operator:list` is asked, and the answer *nobody, any more* is the
     * most useful thing it can say about an address somebody is looking for. A
     * list that quietly dropped withdrawn accounts would make a revocation
     * indistinguishable from a row that was never created, which is exactly the
     * distinction somebody suspecting a leak is trying to draw.
     *
     * Ordered by address rather than by creation, because the reader arrives
     * with an address in mind.
     *
     * @return list<Operator>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['email' => 'ASC']);
    }

    /**
     * How many operators can still get in — the number the last-operator refusal
     * is about (XIV-92).
     *
     * Counted in SQL, unlike `UserManager::refuseIfLastAdmin()`'s loop over
     * users. That one counts in PHP because *administrator* is a string inside a
     * JSON column and there is no honest predicate for it; this is a boolean
     * column with an index-shaped question, and the two situations only look
     * alike.
     */
    public function countActive(): int
    {
        return $this->count(['active' => true]);
    }
}

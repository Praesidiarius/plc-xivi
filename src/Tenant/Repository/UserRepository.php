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

use App\Tenant\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 *
 * Resolves through the `tenant` manager, so every query here reaches whichever
 * customer's database the current request resolved to — and throws rather than
 * guessing when there is no tenant.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function emailIsTaken(string $email): bool
    {
        return $this->findOneByEmail($email) !== null;
    }

    /**
     * How many people this customer has, and when any of them last signed in
     * (XIV-59).
     *
     * **One row out of one query, and no user is loaded.** That is the shape the
     * usage collector needs and it is also the shape §8.11 insists on: an
     * operator tool may know that twelve people work here and that one of them
     * was in on Tuesday, and it may not know who they are. A `findAll()` and a
     * `usort` would have produced the same two numbers while pulling every
     * customer's names, emails and password hashes through the control plane's
     * process to get them, which is a different thing entirely — the answer would
     * be identical and the boundary would be gone.
     *
     * Every user counts, including deactivated ones. A deactivated account is
     * still an account somebody set up, and the sign-in date beside it is the
     * figure that says whether anybody is actually here — which is the question
     * the count on its own was never going to answer.
     *
     * `MAX(last_login_at)` over an empty table is null, and so is the maximum
     * over a customer whose users have all never signed in. Both are *never*,
     * which is the honest reading of each, and neither is confused with "we could
     * not look" — that state is the absence of a successful collection, not a
     * null in one (see {@see \Xivi\ControlPlane\Entity\TenantUsage}).
     *
     * @return array{users: int, lastLoginAt: ?\DateTimeImmutable}
     */
    public function countAndLastSignIn(): array
    {
        /** @var array{users: int|string, last: \DateTimeImmutable|string|null} $row */
        $row = $this->createQueryBuilder('u')
            ->select('COUNT(u.id) AS users', 'MAX(u.lastLoginAt) AS last')
            ->getQuery()
            ->getSingleResult();

        $last = $row['last'];

        return [
            'users' => (int) $row['users'],
            // An aggregate is not a mapped field, so nothing converts it for us:
            // Postgres hands back the timestamp as a string through DQL's scalar
            // hydration. Accepting both shapes rather than asserting one keeps
            // this working if a driver or a Doctrine release starts converting it.
            'lastLoginAt' => match (true) {
                $last === null => null,
                $last instanceof \DateTimeImmutable => $last,
                default => new \DateTimeImmutable($last),
            },
        ];
    }
}

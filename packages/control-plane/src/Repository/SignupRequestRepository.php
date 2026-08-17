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
use Xivi\ControlPlane\Entity\SignupRequest;

/**
 * Live self-service signups, out of the control-plane database (XIV-64).
 *
 * Three questions and no more, which is deliberate. This table is written by an
 * anonymous endpoint, so every method here is a method something on the open
 * internet can cause to run — and each of the three is a lookup by a value the
 * caller already had to know: their own address, the name they are asking for,
 * or the token that was mailed to them. Nothing here lists, counts or searches,
 * because nothing on the public side has any business enumerating who else has
 * signed up.
 *
 * @extends ServiceEntityRepository<SignupRequest>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class SignupRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SignupRequest::class);
    }

    /**
     * The one live signup for an address, in whatever state it is in.
     *
     * "The one" is the unique index doing the work rather than a hopeful
     * `findOneBy`: a second submission from an address replaces the row this
     * returns rather than adding to it.
     */
    public function findOneByEmail(string $email): ?SignupRequest
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Whether a confirmed signup is holding this name.
     *
     * Only confirmed rows hold anything, which is the anti-squatting rule stated
     * as a query: an unconfirmed signup for `acme` does not stop anybody else
     * asking for `acme`, and the first address that confirms is the one that
     * gets it.
     */
    public function slugIsReserved(string $slug): bool
    {
        return $this->findOneBy(['reservedSlug' => $slug]) !== null;
    }

    /**
     * The signup a confirmation link belongs to.
     *
     * Takes the *hash*, because that is what is stored — see
     * {@see SignupRequest} for why this table holds a digest of the token rather
     * than the token. The caller hashes what arrived and looks the digest up, so
     * a database that leaks leaks nothing anybody can present.
     */
    public function findOneByConfirmationTokenHash(#[\SensitiveParameter] string $hash): ?SignupRequest
    {
        return $this->findOneBy(['confirmationTokenHash' => $hash]);
    }
}

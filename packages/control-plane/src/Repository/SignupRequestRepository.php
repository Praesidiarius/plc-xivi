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
use Xivi\ControlPlane\Entity\SignupStatus;

/**
 * Live self-service signups, out of the control-plane database (XIV-64).
 *
 * Three questions the public side may ask, and one it may not.
 *
 * The first three are deliberate and were the whole class until XIV-98. This
 * table is written by an anonymous endpoint, so every method reachable from it
 * is a method something on the open internet can cause to run — and each of
 * those three is a lookup by a value the caller already had to know: their own
 * address, the name they are asking for, or the token that was mailed to them.
 * None of them lists, counts or searches, because nothing on the public side has
 * any business enumerating who else has signed up.
 *
 * {@see findConfirmed()} is the fourth and it enumerates outright. It is added
 * here rather than in a repository of its own because a second
 * `ServiceEntityRepository` over one entity is two objects that can disagree
 * about what a `SignupRequest` is, which is a worse trade than the one it would
 * buy. What keeps the rule above true is not that the method is absent but that
 * **nothing on the public side calls it**: its one caller is
 * {@see \Xivi\ControlPlane\Provisioning\SignupProvisioner}, reached only from
 * a console command, and `SignupEndpointTest` already asserts that the
 * provisioner is not in either public controller's constructor graph. The
 * boundary is where it always was; this is the non-public half of the feature
 * asking the question only it is entitled to ask.
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

    /**
     * Every confirmed signup waiting to become a customer, oldest first
     * (XIV-98).
     *
     * **Confirmed and nothing else.** A pending row is an address that has
     * proven nothing — anybody can type anybody's — and provisioning one would
     * undo the entire gate §8.12 built. The status is the filter and there is no
     * second condition beside it: this table holds *live* signups only, so a row
     * that has become a tenant is gone rather than marked, and "confirmed" and
     * "waiting" are the same set by construction.
     *
     * **Oldest first**, by the moment the address answered rather than by the
     * moment the row was written. Those differ — somebody can submit on Monday
     * and click on Tuesday — and the queue position that is fair is the one
     * earned by confirming, since a submission holds nothing. It also decides
     * who wins a race for a name that two people asked for, in the same
     * direction the reservation itself already decided it.
     *
     * Ordering matters here in a way it does not for `tenant:usage:collect`,
     * whose work commutes. This loop creates databases one at a time and can run
     * out of something — disk, connections, patience — half way through, and
     * when it does the customers who confirmed first should be the ones already
     * served.
     *
     * @return list<SignupRequest>
     */
    public function findConfirmed(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :confirmed')
            ->setParameter('confirmed', SignupStatus::Confirmed)
            ->orderBy('s.confirmedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

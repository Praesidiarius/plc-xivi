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
 * {@see findConfirmed()} is the fourth and it enumerates outright,
 * {@see findAbandonedPending()} is the fifth (XIV-125) and
 * {@see findFailed()} the sixth (XIV-108). All three are added here rather than
 * in a repository of their own because a second `ServiceEntityRepository` over
 * one entity is two objects that can disagree about what a `SignupRequest` is,
 * which is a worse trade than the one it would buy. What keeps the rule above
 * true is not that the methods are absent but that **nothing on the public side
 * calls them**: their callers are
 * {@see \Xivi\ControlPlane\Provisioning\SignupProvisioner},
 * {@see \Xivi\ControlPlane\Command\PruneSignupsCommand} and
 * {@see \Xivi\ControlPlane\Signup\StalledSignups}, reached from a console or
 * from behind the control-plane firewall, and `SignupEndpointTest` already
 * asserts that the provisioner is not in either public controller's constructor
 * graph. The boundary is where it always was; this is the non-public half of the
 * feature asking the questions only it is entitled to ask.
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

    /**
     * Signups whose address never answered, long enough ago that nothing is
     * waiting on them (XIV-125).
     *
     * **`Pending` is half the filter and it is the important half.** A confirmed
     * row is holding a name and is on its way to being a customer; it is removed
     * by provisioning and by nothing else. A pending row holds nothing at all,
     * `reserved_slug` being NULL is how the schema says so, so removing one
     * releases nothing and races with nothing.
     *
     * The other half is the confirmation window, not the creation date, because
     * the window is what makes a row dead: `confirmation_expires_at` has already
     * passed for every row this returns, so the link in that person's inbox
     * stopped working before the caller's cutoff was even applied. Ordered by it
     * for the same reason, oldest first, so that a run reads as a queue draining
     * rather than as a set.
     *
     * @param \DateTimeImmutable $expiredBefore the cutoff, which is well past the
     *                                          window rather than at it: see
     *                                          {@see \Xivi\ControlPlane\Command\PruneSignupsCommand}
     *
     * @return list<SignupRequest>
     */
    public function findAbandonedPending(\DateTimeImmutable $expiredBefore): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :pending')
            ->andWhere('s.confirmationExpiresAt < :cutoff')
            ->setParameter('pending', SignupStatus::Pending)
            ->setParameter('cutoff', $expiredBefore)
            ->orderBy('s.confirmationExpiresAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every signup a provisioning run has failed on, oldest failure first
     * (XIV-108).
     *
     * **Failed, and not yet judged stalled.** The predicate is
     * `provisioning_stage IS NOT NULL`, which is a plain column test meaning
     * *something has gone wrong here at least once*. Which of those failures
     * will never resolve is
     * {@see \Xivi\ControlPlane\Provisioning\SignupProvisioningStage::isWorthRetrying()}'s
     * answer, and it is asked in PHP by the caller rather than translated into a
     * DQL `s.provisioningStage = :preflight`. That is
     * {@see \Xivi\ControlPlane\Controller\TenantListController}'s argument for
     * sorting by `attentionRank()` in PHP, one table along: a second copy of the
     * rule, in a different language, is a copy nothing would notice diverging
     * from the enum, and here the divergence would be a page offering to
     * apologise for a signup that is about to succeed.
     *
     * The cost is a list of failed signups fetched and mostly discarded. In the
     * ordinary life of this table that list is empty, because a confirmed signup
     * is provisioned by the next cron run and the row is deleted; a bad
     * afternoon makes it a handful. If it is ever large enough for the filtering
     * to matter, something is wrong that this page cannot fix.
     *
     * **Oldest failure first**, so the page reads as a queue of people who have
     * been waiting, longest wait at the top. `provisioning_failed_at` moves with
     * every attempt, so a signup failing every five minutes since Tuesday and
     * one that first failed an hour ago carry timestamps minutes apart. That is
     * the right ordering anyway for the question this page asks, which is *who
     * is still waiting*, and the row shows the attempt count beside it so the
     * difference between the two is visible where the reader can use it.
     *
     * @return list<SignupRequest>
     */
    public function findFailed(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.provisioningStage IS NOT NULL')
            ->orderBy('s.provisioningFailedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

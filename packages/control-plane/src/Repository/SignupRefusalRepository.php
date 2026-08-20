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
use Xivi\ControlPlane\Entity\SignupRefusal;

/**
 * The tally of signups turned away at the door (XIV-125).
 *
 * Two methods pointing in opposite directions: {@see record()} is called by an
 * anonymous internet-facing request and writes, {@see newestFirst()} is called
 * by an operator's page and reads. That is the reverse of
 * {@see SignupRequestRepository}, whose docblock explains why nothing on the
 * public side may enumerate anything, and the rule survives here for the reason
 * given on {@see SignupRefusal}: the public side can only ever increment a
 * counter for a domain this installation itself put on a list, so there is
 * nothing here to enumerate and nothing a stranger's input decides.
 *
 * @extends ServiceEntityRepository<SignupRefusal>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class SignupRefusalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SignupRefusal::class);
    }

    /**
     * One more signup refused from this domain.
     *
     * **One SQL statement rather than a find, a mutation and a flush**, and both
     * halves of that matter on a path an anonymous caller controls the frequency
     * of.
     *
     * The first is correctness under concurrency. Two refusals arriving in the
     * same instant for a domain with no row yet would both find nothing, both
     * insert, and one of them would hit `uniq_signup_refusal_domain`; two
     * arriving for a domain that does have a row would read the same counter and
     * write the same number back, losing one. `ON CONFLICT (domain) DO UPDATE`
     * has neither problem, because PostgreSQL does the read and the write in one
     * statement under one lock. The alternative is a retry loop around an
     * optimistic write, which is more code for a worse guarantee.
     *
     * The second is that this must not be able to break the request it is part
     * of. A failed ORM flush leaves the entity manager *closed*, and the entity
     * manager here is the one {@see \Xivi\ControlPlane\Signup\SignupIntake} is
     * about to use for the next visitor. A statement on the connection touches
     * neither the unit of work nor the identity map, so the worst this can do is
     * fail on its own terms.
     *
     * Not swallowed, deliberately. If this throws, the control-plane database is
     * unreachable, which is a condition every other write on this endpoint fails
     * on too. Catching it here would buy one tidy refusal at the price of the
     * guarantee the whole table exists for, which is that a refusal is never
     * silent.
     */
    public function record(string $domain): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO signup_refusal (domain, attempts, first_seen_at, last_seen_at)
                VALUES (:domain, 1, :now, :now)
                ON CONFLICT (domain) DO UPDATE
                SET attempts = signup_refusal.attempts + 1,
                    last_seen_at = EXCLUDED.last_seen_at
                SQL,
            [
                'domain' => $domain,
                // Formatted here rather than handed over as an object, because
                // this is DBAL rather than the ORM and the column is
                // `TIMESTAMP(0) WITHOUT TIME ZONE`. The process runs in UTC
                // (§8.4.4), so there is no zone to lose.
                'now' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Everything that has been refused, the most recent provider first.
     *
     * Ordered by when it last happened rather than by how often, because the
     * question an operator opens this with is *is this still going on*. A domain
     * with four hundred attempts that stopped in March is history; one with three
     * from this morning is either a script starting up or a line in the list that
     * is refusing somebody real.
     *
     * No limit and no paging. The table has one row per entry in
     * {@see \Xivi\ControlPlane\Signup\DisposableEmailDomains::DOMAINS} at the
     * very most, which is a couple of dozen, and a page that hid part of a list
     * that short would be hiding the interesting part half the time.
     *
     * @return list<SignupRefusal>
     */
    public function newestFirst(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.lastSeenAt', 'DESC')
            ->addOrderBy('r.domain', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

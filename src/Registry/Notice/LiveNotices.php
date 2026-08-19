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

namespace App\Registry\Notice;

use App\Registry\Entity\Notice;
use App\Registry\Entity\NoticeAudience;
use App\Registry\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the operator of this installation is currently saying to one customer
 * (XIV-120, docs/architecture/identity-and-access.md §8.16).
 *
 * **The one query on a customer's request path that touches this feature**, and
 * the reason the feature is cheap. §4.4 gives the customer-facing instance
 * `SELECT` on the registry tables and no write privilege anywhere in that
 * database — which was the constraint that sent [XIV-102]'s purchase requests
 * into the customer's own database and required a collector to fetch them back.
 * A notice goes the other way: an operator writes it where the schema is owned,
 * a customer reads it where they work, and reading is exactly what the grant
 * already permits. So there is no copy, no interval, and nothing that can be
 * stale.
 *
 * ## Why this is a plain service and not a `ServiceEntityRepository`
 *
 * Because the claim *"a customer's instance needs no privilege it has not
 * already got"* is only worth what it can be tested with, and the honest test is
 * to run **this class** against **that role**. A `ServiceEntityRepository`
 * resolves its own connection out of the `ManagerRegistry`, so a test could only
 * ever exercise it as whoever the suite connects as — which is a privileged
 * account, and a test that proves nothing while passing.
 *
 * Taking the entity manager as a constructor argument means
 * `tests/Functional/Deployment/NoticeGrantsTest.php` can hand it one built on a
 * connection *as the restricted role*, run the real query, and let PostgreSQL
 * answer. Everything else about the class is ordinary; this one property is the
 * ticket's central claim being made checkable.
 *
 * ## Every clause is a filter the database applies
 *
 * Deliberately, and it is worth listing because each one is a way this feature
 * could leak:
 *
 * * **Live now** — published, and not yet expired. Withdrawing is an expiry set
 *   to now ({@see Notice::withdraw()}), so it needs no clause of its own.
 * * **Addressed here** — for every customer, or naming this one. A notice
 *   addressed to somebody else must not appear, and "the template does not draw
 *   it" is not the same guarantee as "the query did not return it".
 * * **For this reader** — {@see NoticeAudience}, per notice, so that a trial
 *   ending reaches whoever can act on it and a maintenance window reaches
 *   everybody.
 *
 * The alternative — load the installation's notices and filter in PHP — is the
 * version where a second caller quietly gets an unfiltered list, and it is also
 * the version where every dashboard in the installation reads every notice ever
 * written.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class LiveNotices
{
    public function __construct(
        /**
         * The control plane's, which is the default manager here — a notice is a
         * registry row (§3.1) and this is the only database it is in.
         */
        private EntityManagerInterface $control,
    ) {
    }

    /**
     * Every notice this customer's people should be seeing, newest first.
     *
     * @param bool $administrator whether the reader holds `ROLE_ADMIN`, which is
     *                            the only distinction {@see NoticeAudience} draws
     *                            and is passed in rather than resolved here: this
     *                            class knows about a registry and a moment, and
     *                            reaching for the security token would give it an
     *                            opinion about which of the two instances it is
     *                            running in
     *
     * @return list<Notice>
     */
    public function forTenant(Tenant $tenant, bool $administrator, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        // An EXISTS rather than a LEFT JOIN, so that the row count is the number
        // of notices rather than the number of notices times their recipients —
        // which would need a DISTINCT, and a DISTINCT is a way of saying the
        // query returned things it should not have and they were dropped
        // afterwards.
        $query = $this->control->createQuery(
            <<<'DQL'
                SELECT n FROM App\Registry\Entity\Notice n
                WHERE n.publishedAt <= :now
                  AND (n.expiresAt IS NULL OR n.expiresAt > :now)
                  AND n.audience IN (:audiences)
                  AND (
                        n.everyTenant = true
                        OR EXISTS (
                            SELECT r.id FROM App\Registry\Entity\NoticeRecipient r
                            WHERE r.notice = n AND r.tenant = :tenant
                        )
                  )
                ORDER BY n.publishedAt DESC, n.id DESC
                DQL,
        );

        $query->setParameter('now', $now);
        $query->setParameter('tenant', $tenant->getId());
        $query->setParameter('audiences', array_map(
            static fn (NoticeAudience $audience): string => $audience->value,
            NoticeAudience::visibleTo($administrator),
        ));

        /** @var list<Notice> $notices */
        $notices = $query->getResult();

        return $notices;
    }
}

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
use App\Registry\Entity\NoticeReach;
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
 * * **On this channel**: {@see NoticeReach}, and XIV-166 added it as a `WHERE`
 *   clause rather than as a filter on the way out for a reason that is about
 *   cost rather than about leakage. The dashboard's notices are read once, when
 *   somebody opens the dashboard; the every-page notices are read by the shell
 *   **on every request a signed-in customer makes, all day**. If that read
 *   returned dashboard notices and dropped them afterwards, then an installation
 *   whose only live notice is a dashboard card would pay a non-empty result on
 *   every page, and `NoticeInbox` would go on to ask the *customer's own
 *   database* about dismissals for a notice nobody was ever going to be shown.
 *   Filtered in the clause, that second query does not happen, and the default
 *   reach stays free everywhere except the one page it is for.
 *
 * The alternative — load the installation's notices and filter in PHP — is the
 * version where a second caller quietly gets an unfiltered list, and it is also
 * the version where every dashboard in the installation reads every notice ever
 * written.
 *
 * ## What this costs, measured rather than assumed (XIV-166)
 *
 * It is worth measuring because the every-page reach made this query run on
 * **every request a signed-in customer makes**, where before XIV-166 it ran once
 * when somebody opened a dashboard. So the figures, taken against a control plane
 * seeded with twenty notices, nineteen of them expired, one live and addressed to
 * everybody, `ANALYZE`d first:
 *
 * ```
 * Sort  (cost=164.86..164.86 rows=1) (actual time=0.014..0.015 rows=1 loops=1)
 *   ->  Seq Scan on notice  (actual time=0.008..0.008 rows=1 loops=1)
 *         Rows Removed by Filter: 19
 *         Buffers: shared hit=1
 * Execution Time: 0.048 ms
 * ```
 *
 * **One shared buffer**, because the whole table is one page: `notice` is
 * something an operator types into by hand, so a busy installation holds tens of
 * rows and not thousands, and PostgreSQL sequentially scans a page-sized table
 * whatever indexes it is offered. The `EXISTS` against `notice_recipient` is
 * `never executed` on a notice addressed to everybody, which is the ordinary
 * case, and has an index on `tenant_id` when it is not.
 *
 * End to end, 200 executions through DBAL against the container's PostgreSQL
 * averaged **0.24 ms each**, and the same loop against an *empty* `notice` table
 * averaged 0.37 ms. That the empty table measured slower is the useful part of
 * the result: the query is so far below the round trip that the two figures are
 * measuring the socket rather than the scan, and the difference between them is
 * noise. A quarter of a millisecond on a connection that is **already open
 * because resolving the tenant needed it** (§7.4) is the whole per-request cost
 * of this feature.
 *
 * That is why there is no cache here and no index on `reach`. A cache would be a
 * second copy of a fact an operator changes by pressing Withdraw, bought for less
 * time than it takes to build the response object; an index on a one-page table
 * would cost a write on every publish, occupy space in every backup and never be
 * chosen by the planner.
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
     * @param bool        $administrator whether the reader holds `ROLE_ADMIN`, which is
     *                                   the only distinction {@see NoticeAudience} draws
     *                                   and is passed in rather than resolved here: this
     *                                   class knows about a registry and a moment, and
     *                                   reaching for the security token would give it an
     *                                   opinion about which of the two instances it is
     *                                   running in
     * @param NoticeReach $reach         which of the two channels is being drawn.
     *                                   Required rather than defaulted, and rather than
     *                                   nullable-for-both: a caller that has not said which
     *                                   surface it is rendering is a caller about to draw
     *                                   the same notice twice on the dashboard, and there is
     *                                   no honest default between a card and a banner
     *
     * @return list<Notice>
     */
    public function forTenant(
        Tenant $tenant,
        bool $administrator,
        NoticeReach $reach,
        ?\DateTimeImmutable $now = null,
    ): array {
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
                  AND n.reach = :reach
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
        // The backed value rather than the case, matching the audiences below:
        // the column is a `VARCHAR(32)` that Doctrine reads back through
        // `enumType`, and DQL comparing a column to a PHP enum instance depends
        // on the parameter conversion noticing the mapping. Passing the string is
        // what the audience clause beside it already does, and one habit here is
        // worth more than two correct ones.
        $query->setParameter('reach', $reach->value);
        $query->setParameter('audiences', array_map(
            static fn (NoticeAudience $audience): string => $audience->value,
            NoticeAudience::visibleTo($administrator),
        ));

        /** @var list<Notice> $notices */
        $notices = $query->getResult();

        return $notices;
    }
}

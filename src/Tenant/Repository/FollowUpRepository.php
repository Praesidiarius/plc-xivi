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

use App\Tenant\Entity\FollowUp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * @extends ServiceEntityRepository<FollowUp>
 *
 * Reading follow-ups back, and the one rule every read here has to obey (XIV-80).
 *
 * **A follow-up whose record is soft-deleted must not surface.** There is no
 * foreign key on `record_id` — see {@see FollowUp} for why there cannot be — and
 * records are soft-deleted rather than removed (`RecordRepository::delete()` sets
 * `deleted_at`), so nothing in the database will hide these rows and no cascade
 * would fire even if there were one to fire. Every method here therefore checks,
 * and a new one that forgets is a page listing work about a customer somebody
 * deleted last month.
 *
 * **The check is a second query rather than a join, and that is forced.** A
 * module's records live in a table whose name is only known at runtime — it is on
 * the customer's own {@see \Xivi\Core\Entity\ModuleDefinition} — and it is not a
 * mapped entity, so DQL cannot join to it and a QueryBuilder cannot name it. The
 * options were raw SQL with a table name interpolated per module, or asking which
 * of these ids are still alive afterwards. This takes the second: one extra query
 * per module involved, against a primary key, on a list that is already small.
 *
 * Reading is governed by the module's own View grant (§8.4) and is deliberately
 * not checked here — a repository that quietly filtered by permission would be a
 * second seam disagreeing with the route's. The write path is the one that
 * enforces, and it is {@see \App\Tenant\FollowUp\FollowUpManager}.
 *
 * Resolves through the `tenant` manager, so a follow-up is only ever read from
 * the database of the customer being served (§8.1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class FollowUpRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly MetadataRepository $metadata,
    ) {
        parent::__construct($registry, FollowUp::class);
    }

    /**
     * Everything on one record: open first, then by when it wants doing.
     *
     * The record page's question, and the reason for the `(module, record_id)`
     * index. Open before done because a done follow-up is history and an open one
     * is work — a list that interleaved them by date would bury today's job under
     * last month's finished ones.
     *
     * @return list<FollowUp>
     */
    public function forRecord(string $moduleKey, int $recordId): array
    {
        /** @var list<FollowUp> $found */
        $found = $this->createQueryBuilder('f')
            ->andWhere('f.module = :module')
            ->andWhere('f.recordId = :record')
            ->setParameter('module', $moduleKey)
            ->setParameter('record', $recordId)
            // A NULL done_at sorts last in Postgres by default, and "still to do"
            // is the half anybody opened this page for.
            ->orderBy('f.doneAt', 'ASC')
            ->addOrderBy('f.dueAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->onLivingRecords($found);
    }

    /**
     * What is still on one person's list, soonest first (XIV-81's question).
     *
     * Here rather than in the widget's own ticket because the index that serves
     * it — `(assignee_id, done_at, due_at)` — is being created by this migration,
     * and an index nothing asks for is one nobody can tell is right. XIV-81 added
     * the bound, which is the third column of that index finally being used for
     * something.
     *
     * **No limit, deliberately.** Cutting the list off before the soft-delete
     * filter runs would hand back short pages — ask for ten, get seven, with the
     * three missing ones invisible — and cutting it off afterwards is a decision
     * about how a widget looks, which belongs to the widget.
     *
     * @param \DateTimeImmutable|null $dueBefore the exclusive end of the window
     *                                           the caller is asking about, or
     *                                           null for everything still open.
     *                                           {@see \App\Tenant\FollowUp\FollowUpLens}
     *                                           is what draws it, in the reader's
     *                                           own zone
     *
     * @return list<FollowUp>
     */
    public function openFor(int $assigneeId, ?\DateTimeImmutable $dueBefore = null): array
    {
        $query = $this->createQueryBuilder('f')
            // **The notes come along, and this join is not decoration.** The
            // widget prints what a follow-up *says* — its opening note, since a
            // priority and a date on their own describe nothing — and
            // `FollowUp::$notes` is a lazy collection. Touching it per row would
            // be a query per follow-up: the same N+1 this whole ticket is about,
            // arriving through the object graph rather than through the module
            // tables. One LEFT JOIN, and object hydration deduplicates the roots.
            //
            // The notes' own order has to be restated here because the
            // association's `#[ORM\OrderBy]` only governs a lazy load; a
            // fetch-join is ordered by the statement.
            ->leftJoin('f.notes', 'n')
            ->addSelect('n')
            ->andWhere('f.assigneeId = :assignee')
            ->andWhere('f.doneAt IS NULL')
            ->setParameter('assignee', $assigneeId)
            ->orderBy('f.dueAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->addOrderBy('n.createdAt', 'ASC')
            ->addOrderBy('n.id', 'ASC');

        if ($dueBefore !== null) {
            // **An upper bound and no lower one, which is not an oversight and is
            // deliberately the opposite of §5.16.** An invoice is overdue
            // *strictly before* today, because calling somebody late on the
            // morning their bill falls due is how a dunning list loses its
            // credibility. A follow-up is the other case: it is a note somebody
            // wrote to themselves, and what is due at 16:30 is exactly what they
            // want to see at 09:00 — so the window closes at the *end* of the
            // period rather than at its start.
            //
            // And nothing closes it at the near end. Adding `AND f.dueAt >= …`
            // would look like consistency and would mean a follow-up somebody
            // missed disappearing from the widget at the moment it started to
            // matter. If a future reader is here to "fix" the asymmetry between
            // this and the invoice: the asymmetry is the feature, and the two
            // predicates answer opposite questions about opposite kinds of
            // deadline.
            //
            // Exclusive, because the caller hands in the start of the day after
            // the last one included — comparing against the last representable
            // microsecond of a day is an off-by-one nobody finds twice.
            $query->andWhere('f.dueAt < :dueBefore')->setParameter('dueBefore', $dueBefore);
        }

        /** @var list<FollowUp> $found */
        $found = $query->getQuery()->getResult();

        return $this->onLivingRecords($found);
    }

    /**
     * Takes a departed user off every follow-up they were on, keeping their name
     * on it.
     *
     * A bulk DQL update rather than a loop of loaded entities, because it is one
     * statement about a set and nothing here has any use for the rows: deleting a
     * user is rare, and touching each follow-up's `updated_at` on the way past
     * would say the thread had activity on the day somebody left the company.
     *
     * The counterpart of the missing foreign key. `ON DELETE SET NULL` would do
     * this in the database if there were a constraint to hang it on, and there
     * deliberately is not (see {@see FollowUp}), so it is a listener's job —
     * {@see \App\Tenant\EventListener\UserRemovedListener}.
     *
     * @return int how many follow-ups were let go of
     */
    public function clearAssignee(int $userId): int
    {
        return (int) $this->createQueryBuilder('f')
            ->update()
            ->set('f.assigneeId', ':nobody')
            ->andWhere('f.assigneeId = :user')
            ->setParameter('nobody', null)
            ->setParameter('user', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * The ones whose record is still there.
     *
     * Grouped by module first, so the number of queries is the number of modules
     * involved rather than the number of follow-ups — the difference between one
     * extra query and fifty on a busy person's list.
     *
     * A module the customer no longer has drops out entirely, which is the same
     * answer as a deleted record and for the same reason: there is nothing to
     * open. It is not treated as an error, because a grant on an uninstalled
     * module is already defined to go inert rather than to explode (§8.4), and a
     * follow-up on one should behave the same way.
     *
     * @param list<FollowUp> $followUps
     *
     * @return list<FollowUp>
     */
    private function onLivingRecords(array $followUps): array
    {
        if ($followUps === []) {
            return [];
        }

        /** @var array<string, list<int>> $byModule */
        $byModule = [];

        foreach ($followUps as $followUp) {
            $byModule[$followUp->getModule()][] = $followUp->getRecordId();
        }

        /** @var array<string, array<int, true>> $alive */
        $alive = [];

        foreach ($byModule as $moduleKey => $recordIds) {
            $module = $this->metadata->find($moduleKey);

            if ($module === null) {
                continue;
            }

            // The table name comes off the definition and never out of the
            // follow-up row, which is what keeps this from being the string
            // concatenation it looks like: a module's table is chosen by the
            // module author, validated at install, and stored (§5.1).
            $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
                sprintf('SELECT id FROM %s WHERE id IN (:ids) AND deleted_at IS NULL', $module->getTableName()),
                ['ids' => array_values(array_unique($recordIds))],
                ['ids' => ArrayParameterType::INTEGER],
            );

            foreach ($ids as $id) {
                $alive[$moduleKey][(int) $id] = true;
            }
        }

        return array_values(array_filter(
            $followUps,
            static fn (FollowUp $followUp): bool => isset($alive[$followUp->getModule()][$followUp->getRecordId()]),
        ));
    }
}

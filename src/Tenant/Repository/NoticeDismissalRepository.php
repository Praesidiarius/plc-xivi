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

use App\Tenant\Entity\NoticeDismissal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Which of the operator's notices this person has put away (XIV-120).
 *
 * Resolves through the `tenant` manager, like every repository in this
 * namespace, so a dismissal is only ever read out of the database of the
 * customer being served (§7.4).
 *
 * @extends ServiceEntityRepository<NoticeDismissal>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class NoticeDismissalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoticeDismissal::class);
    }

    /**
     * Of these notices, the ids this person has already dismissed.
     *
     * **Asked about a known set rather than about everything they have ever
     * dismissed**, which is one query either way and a different amount of work
     * as the years pass: the set is what is live for them today — usually none,
     * occasionally two — while their history is unbounded. It also makes the
     * caller's filter a set intersection rather than a lookup with a fallback.
     *
     * An empty set short-circuits, because `IN ()` is neither valid SQL nor a
     * question worth asking.
     *
     * @param list<int> $noticeIds
     *
     * @return list<int>
     */
    public function dismissedBy(int $userId, array $noticeIds): array
    {
        if ($noticeIds === []) {
            return [];
        }

        /** @var list<array{noticeId: int}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.noticeId')
            ->where('d.userId = :user')
            ->andWhere('d.noticeId IN (:notices)')
            ->setParameter('user', $userId)
            ->setParameter('notices', $noticeIds)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => $row['noticeId'], $rows);
    }

    /** Whether this person has already put this one away. */
    public function has(int $userId, int $noticeId): bool
    {
        return $this->findOneBy(['userId' => $userId, 'noticeId' => $noticeId]) instanceof NoticeDismissal;
    }
}

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

namespace Xivi\Core\ValueList;

use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Entity\ValueList;
use Xivi\Core\Metadata\MetadataCache;

/**
 * Reads the shared lists installed in whichever database the entity manager
 * points at (XIV-127).
 *
 * {@see \Xivi\Core\Metadata\MetadataRepository}'s twin, and written the same way
 * for the same three reasons:
 *
 *  * **cached for as long as the tenant does not move**, in that class's own
 *    cache rather than in a second one — see {@see MetadataCache::list()} for why
 *    sharing the `clear()` is the point;
 *  * **entries are fetch-joined rather than left lazy**, which is a correctness
 *    decision and not a performance one. A list read inside one tenant's context
 *    and touched outside it would load its entries on whatever connection is
 *    current — throwing if none is resolved, and quietly reading the *other*
 *    customer's database if one is (§7.4). A fully loaded list is safe to hold;
 *  * **the parent is joined too**, because {@see ValueList::inTreeOrder()} asks
 *    every entry who its parent is and a lazy proxy per entry would be the N+1
 *    this whole shape exists to avoid.
 *
 * Read only. Everything that writes is {@see ValueListEditor}'s, on the same
 * split {@see \Xivi\Core\Metadata\MetadataEditor} makes: the refusals belong
 * with the writes, and a reader that could also write is a reader every caller
 * has to be trusted with.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ValueLists
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MetadataCache $cache,
    ) {
    }

    public function find(string $key): ?ValueList
    {
        return $this->cache->list($key, fn (): ?ValueList => $this->read($key));
    }

    private function read(string $key): ?ValueList
    {
        return $this->entityManager
            ->createQuery(sprintf(
                'SELECT l, e, p FROM %s l
                 LEFT JOIN l.entries e
                 LEFT JOIN e.parent p
                 WHERE l.key = :key
                 ORDER BY e.position ASC, e.id ASC',
                ValueList::class,
            ))
            ->setParameter('key', $key)
            ->getOneOrNullResult();
    }

    /** @throws ValueListNotFound */
    public function get(string $key): ValueList
    {
        return $this->find($key) ?? throw ValueListNotFound::named($key);
    }

    public function exists(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /** @return list<ValueList> */
    public function all(): array
    {
        return $this->cache->lists($this->readAll(...));
    }

    /** @return list<ValueList> */
    private function readAll(): array
    {
        return $this->entityManager
            ->createQuery(sprintf(
                'SELECT l, e, p FROM %s l
                 LEFT JOIN l.entries e
                 LEFT JOIN e.parent p
                 ORDER BY l.label ASC, e.position ASC, e.id ASC',
                ValueList::class,
            ))
            ->getResult();
    }

    /**
     * key => the customer's own label, for a select.
     *
     * Sorted by label rather than by key, on the same terms as the module select
     * beside it (XIV-144): it is read as a list of words, and the key is what is
     * stored rather than what anybody is choosing between.
     *
     * @return array<string, string>
     */
    public function asChoices(): array
    {
        $lists = [];

        foreach ($this->all() as $list) {
            $lists[$list->getKey()] = $list->getLabel();
        }

        return $lists;
    }
}

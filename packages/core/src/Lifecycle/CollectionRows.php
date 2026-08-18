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

namespace Xivi\Core\Lifecycle;

use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * Where a guard's rows come from (XIV-110).
 *
 * A class of its own rather than a closure inside {@see Lifecycles}, because the
 * two lookups it makes are the only part of guarding that has to know how this
 * engine stores anything — everything else in this directory is a graph and a
 * predicate. Keeping it here means {@see GuardedRecord} can be handed a plain
 * `\Closure` and unit-tested with one line, and it means a guard never sees a
 * repository.
 *
 * **The tenant's definitions, not the module's blueprint.** A blueprint says what
 * the module ships with; a customer's own definitions say what they actually have
 * (§6.1), and rows are read out of a table named by the latter. That is also why
 * a collection the tenant does not have is an empty list rather than an
 * exception: a module may have renamed or dropped a collection since a customer
 * installed it, and the right behaviour then is for the guard's own condition to
 * decide against what is really there.
 *
 * Nothing here is cached across records on purpose. A guard is asked about one
 * record at a time — see {@see GuardedRecord} on why a list page never reaches
 * this — and a cache keyed by parent id would be a request-lived buffer of
 * somebody else's rows, which §7.4 has opinions about.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CollectionRows
{
    public function __construct(
        private MetadataRepository $metadata,
        private RecordRepository $records,
    ) {
    }

    /**
     * The rows one record holds in one of its collections.
     *
     * @return list<Record>
     */
    public function of(string $moduleKey, string $collection, ?int $parentId): array
    {
        if ($parentId === null) {
            return [];
        }

        $definition = $this->metadata->find($moduleKey)?->getCollection($collection);

        return $definition === null ? [] : $this->records->findChildren($definition, $parentId);
    }
}

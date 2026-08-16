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

namespace App\Tenant\FollowUp;

use App\Tenant\Entity\FollowUp;
use App\Tenant\Entity\User;
use App\Tenant\Repository\FollowUpRepository;
use App\Tenant\Security\PermissionResolver;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * One person's outstanding follow-ups, resolved back to the records they are
 * about (XIV-81).
 *
 * The read counterpart of {@see FollowUpManager}. It is a service rather than
 * code in the widget for the reason the manager is one: the widget is the first
 * caller and will not be the last — a digest mail, a printed day sheet and an API
 * all want this exact list — and none of those goes through a controller. What
 * the widget owns is how many rows fit on a card; what this owns is which rows
 * there are and how much of each one the reader may be told.
 *
 * ## The N+1 this exists to not write
 *
 * Follow-ups live in one shared table (§5.18), so *finding* them is a single
 * indexed read on `(assignee_id, done_at, due_at)`. Resolving each one back to a
 * title is the part that is not single, because `record_id` means a row in a
 * different table depending on what `module` says — a follow-up on a contact and
 * one on an invoice are two tables, and neither is a mapped entity that DQL could
 * join to.
 *
 * So the work is **grouped by module and read in batches**, never looped:
 * {@see RecordRepository::findAny()} takes a list of ids and answers with one
 * statement. The cost is therefore the number of *modules* somebody has work in —
 * realistically two or three — rather than the number of follow-ups they are
 * carrying. §5.16's closing argument names this by name: counting a dashboard's
 * worth of anything by loading each one and asking it is the N+1 the first page
 * after signing in cannot afford.
 *
 * **The repository has already made a pass of its own**, one query per module, to
 * drop follow-ups whose record has been soft-deleted. Reading the surviving
 * records here is a second pass over a shorter list, and it is worth it rather
 * than being merged: the liveness rule is one XIV-80 made *every* read of that
 * table obey, and a caller that reimplemented it to save a statement would be the
 * first read that could forget. Both passes are `IN (…)` against a primary key on
 * a list the size of a widget.
 *
 * ## What the reader is told
 *
 * Three outcomes per follow-up, and they are genuinely different:
 *
 * * **The record is gone** — soft-deleted, or its module uninstalled. The
 *   follow-up does not appear at all. Handled in the repository.
 * * **The module no longer takes follow-ups**, or the reader may not view it, or
 *   their grant is scoped to their own records and this is not one. The follow-up
 *   appears without its record: they keep the work and do not learn what it is
 *   attached to. See {@see AssignedFollowUp}.
 * * **Otherwise**, it is named and linked.
 *
 * The permission half goes through {@see RecordAccess::fromPermissions()} rather
 * than comparing owner ids in place, which is the same insistence
 * {@see FollowUpManager::assertMay()} makes: that object is what the module list's
 * WHERE clause is compiled from, so a record kept out of somebody's list cannot
 * be named to them here.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class MyFollowUps
{
    public function __construct(
        private FollowUpRepository $followUps,
        private MetadataRepository $metadata,
        private RecordRepository $records,
        private PermissionResolver $permissions,
    ) {
    }

    /**
     * Everything still open on this person's list within this lens, soonest
     * first.
     *
     * Overdue work sorts to the top and stays there in every lens, including
     * *today*, because the lens is an upper bound and nothing bounds the near end
     * — see {@see FollowUpLens} for why that asymmetry is the point.
     *
     * @param \DateTimeZone           $zone   the reader's, from {@see \App\Tenant\Settings\DisplayTimezone}
     * @param string                  $locale the reader's, from {@see \App\Tenant\Settings\FormattingLocale}
     * @param \DateTimeImmutable|null $now    for tests, which cannot wait for a Sunday
     *
     * @return list<AssignedFollowUp>
     */
    public function due(
        User $reader,
        FollowUpLens $lens,
        \DateTimeZone $zone,
        string $locale,
        ?\DateTimeImmutable $now = null,
    ): array {
        $readerId = $reader->getId();

        if ($readerId === null) {
            // A user that has never been saved has no follow-ups by definition,
            // and asking the database for `assignee_id = NULL` would answer with
            // everybody's unassigned work. Failing closed costs nothing here.
            return [];
        }

        $open = $this->followUps->openFor($readerId, $lens->dueBefore($zone, $locale, $now));

        if ($open === []) {
            return [];
        }

        $records = $this->recordsBehind($open, $reader, $readerId);

        return array_map(
            function (FollowUp $followUp) use ($records): AssignedFollowUp {
                $found = $records[$followUp->getModule()][$followUp->getRecordId()] ?? null;

                return $found === null
                    ? new AssignedFollowUp($followUp)
                    : new AssignedFollowUp($followUp, $found[0], $found[1]);
            },
            $open,
        );
    }

    /**
     * The records these follow-ups are about, for the ones the reader may be
     * shown — grouped by module, one query each.
     *
     * A module answers nothing at all, rather than answering per record, in three
     * cases, and each one skips a query rather than filtering its results:
     *
     * * it is not installed here any more, which the repository has already
     *   dropped the follow-ups for and which is re-checked because `find()` is
     *   how the definition is obtained anyway;
     * * the customer has switched follow-ups off for it, in which case §5.18 says
     *   existing ones "stop being offered" — the switch is reversible and deletes
     *   nothing, so this is a module the office has said does not do follow-ups
     *   rather than a permission being applied;
     * * the reader holds no View grant on it, which is the case
     *   {@see AssignedFollowUp} is about.
     *
     * A grant scoped to *own records* still needs the query, because whether a
     * particular record is theirs is a fact about the row. That comparison is the
     * one place a record is dropped after being read, and it is the same
     * comparison the query compiler puts in a WHERE clause for a list.
     *
     * @param list<FollowUp> $followUps
     *
     * @return array<string, array<int, array{ModuleDefinition, Record}>>
     */
    private function recordsBehind(array $followUps, User $reader, int $readerId): array
    {
        /** @var array<string, list<int>> $wanted */
        $wanted = [];

        foreach ($followUps as $followUp) {
            $wanted[$followUp->getModule()][] = $followUp->getRecordId();
        }

        $permissions = $this->permissions->forUser($reader);

        /** @var array<string, array<int, array{ModuleDefinition, Record}>> $found */
        $found = [];

        foreach ($wanted as $moduleKey => $recordIds) {
            $module = $this->metadata->find($moduleKey);

            if ($module === null || !$module->hasFollowUps()) {
                continue;
            }

            $access = RecordAccess::fromPermissions($permissions, $moduleKey, ModuleAction::View, $readerId);

            if ($access->matchesNothing()) {
                continue;
            }

            // One statement for every follow-up this reader has in this module,
            // however many that is. The loop is over modules and nothing else.
            foreach ($this->records->findAny($module, $recordIds) as $record) {
                if ($access->isRestricted() && $record->ownerId !== $access->ownerId()) {
                    // Somebody else's record, seen through a grant scoped to
                    // their own. Left out of the map, which puts the follow-up on
                    // the list without a title — the same answer as no grant at
                    // all, because from the reader's side it is the same fact.
                    continue;
                }

                if ($record->id !== null) {
                    $found[$moduleKey][$record->id] = [$module, $record];
                }
            }
        }

        return $found;
    }
}

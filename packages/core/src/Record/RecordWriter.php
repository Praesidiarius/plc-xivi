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

namespace Xivi\Core\Record;

use Doctrine\DBAL\Connection;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Event\RecordChanged;

/**
 * One user action against one record: the record, its collections, and the
 * history entry that says what happened (§5.2).
 *
 * The only supported way to write a record. RecordRepository still does the
 * statements, but its mutating methods are internal to this class — a caller
 * that saves directly writes no history, and an audit trail with invisible gaps
 * is worse than none.
 *
 * It is an explicitly scoped object rather than a request-scoped buffer that
 * flushes at the end, and that is a correctness decision. Ambient state that
 * outlives the context it was made in is §7.4: on a console command serving
 * several tenants in sequence, a buffer filled for one would flush into the
 * next one's database. A scope you can see at the call site cannot do that.
 *
 * Everything happens in one transaction, and the event is dispatched inside it,
 * so a subscriber that fails takes the change down with it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordWriter
{
    /** The gap left between rows, so one can be moved between two (XIV-21). */
    private const int POSITION_STEP = 10;

    /** @param iterable<ValueDeriver> $derivers */
    public function __construct(
        private Connection $connection,
        private RecordRepository $records,
        private EventDispatcherInterface $events,
        #[AutowireIterator(ValueDeriver::TAG)]
        private iterable $derivers = [],
    ) {
    }

    /**
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $children
     *                                                                                       the full contents of each collection, keyed by collection;
     *                                                                                       rows with an id are kept, rows without are added, and
     *                                                                                       anything missing is removed
     * @param RecordAction|null                                                    $as       what this save *was*, when it was more than an edit —
     *                                                                                       a lifecycle transition writes the same field diff as
     *                                                                                       any other change and deserves a different verb over
     *                                                                                       it (XIV-14). Null lets the writer decide, which is
     *                                                                                       what every ordinary save wants
     */
    public function save(ModuleDefinition $module, Record $record, array $children = [], ?RecordAction $as = null): Record
    {
        return $this->connection->transactional(function () use ($module, $record, $children, $as): Record {
            $isNew = $record->isNew();

            $before = $isNew
                ? []
                : ($this->records->find($module, (int) $record->id, includeDeleted: true)->data ?? []);

            // What the modules work out for themselves, before anything is
            // compared or written (XIV-16). Here rather than after the write, so
            // a derived total is stored once and the history entry describes the
            // record that actually landed — deriving afterwards would mean a
            // second UPDATE and a timeline that reads a step behind.
            $children = $this->derive($module, $record, $children);

            $fields = $this->diff($module, $before, $record->data);

            $this->records->save($module, $record);

            $collections = [];
            foreach ($children as $key => $rows) {
                $collection = $module->getCollection($key) ?? throw new \InvalidArgumentException(sprintf(
                    'Module "%s" has no collection "%s".',
                    $module->getKey(),
                    $key,
                ));

                $touched = $this->writeChildren($collection, (int) $record->id, $rows);

                // A collection nobody typed into says nothing about what
                // somebody did (XIV-16). Its rows restate the lines above them,
                // and the change that moved them is already in this same entry —
                // "VAT 8.1%: 97.20 → 105.30" underneath "quantity 1 → 2" is the
                // same fact, told twice and less clearly.
                if ($touched !== [] && !$collection->isDerived()) {
                    $collections[$key] = $touched;
                }
            }

            $changes = new RecordChanges($fields, $collections);

            // A save that changed nothing is not an event. "Edited, nothing
            // changed" is most of what makes an audit trail unreadable. Creation
            // is always worth recording, even of an empty record.
            if ($isNew || !$changes->isEmpty()) {
                $this->events->dispatch(new RecordChanged(
                    $module,
                    $record,
                    $as ?? ($isNew ? RecordAction::Created : RecordAction::Updated),
                    $changes,
                    new \DateTimeImmutable(),
                ));
            }

            return $record;
        });
    }

    /**
     * The record and its collections, in one entry. The children are soft-deleted
     * by the repository's cascade and deliberately do not each get a line of
     * their own: "deleted" is the fact, and three address removals underneath it
     * are noise.
     */
    public function delete(ModuleDefinition $module, Record $record): void
    {
        if ($record->isNew()) {
            return;
        }

        $this->connection->transactional(function () use ($module, $record): void {
            $this->records->delete($module, $record);

            $this->events->dispatch(new RecordChanged(
                $module,
                $record,
                RecordAction::Deleted,
                new RecordChanges(),
                new \DateTimeImmutable(),
            ));
        });
    }

    /**
     * Let every module with something to say fill in what follows from what was
     * typed (XIV-16, §7.1).
     *
     * Derivers see the record and its rows together, because the interesting
     * derived values need both: an order's total is a fact about its lines, and
     * a subtotal line is a fact about the lines above it.
     *
     * **A derived collection keeps the row ids it already had.** The rows are
     * worked out fresh on every save and carry no id, so writing them as they
     * come would delete three rows and insert three identical ones each time —
     * churning ids, and burning through the sequence for nothing. Matched by
     * position instead, which is the only thing a restatement has to be matched
     * by: what is in row two is whatever the lines put there.
     *
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $children
     *
     * @return array<string, list<array{id: int|null, data: array<string, mixed>}>>
     */
    private function derive(ModuleDefinition $module, Record $record, array $children): array
    {
        $derivation = new Derivation($record->data, $children);
        $derived = false;

        foreach ($this->derivers as $deriver) {
            if ($deriver->supports($module)) {
                $deriver->derive($module, $derivation);
                $derived = true;
            }
        }

        if (!$derived) {
            return $children;
        }

        $record->data = $derivation->fields;
        $rows = $derivation->rows;

        if ($record->isNew()) {
            return $rows;
        }

        foreach ($module->getCollections() as $collection) {
            $key = $collection->getKey();

            if (!$collection->isDerived() || !isset($rows[$key])) {
                continue;
            }

            $existing = $this->records->findChildren($collection, (int) $record->id);

            foreach ($rows[$key] as $index => $row) {
                $rows[$key][$index]['id'] = isset($existing[$index]) ? (int) $existing[$index]->id : null;
            }
        }

        return $rows;
    }

    /**
     * Make one record's collection look like what was submitted, and say what
     * that did.
     *
     * The ids are checked against the rows this parent actually owns. They
     * arrive from a form, so a submission naming somebody else's address is a
     * request to edit another record through a side door; refusing loudly beats
     * a stray UPDATE that no page would ever show.
     *
     * @param list<array{id: int|null, data: array<string, mixed>}> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function writeChildren(CollectionDefinition $collection, int $parentId, array $rows): array
    {
        $existing = [];
        foreach ($this->records->findChildren($collection, $parentId) as $child) {
            $existing[(int) $child->id] = $child;
        }

        $touched = [];
        $kept = [];
        // Numbered in tens, in the order they arrived (XIV-21). Tens rather than
        // ones so that a row can later be typed in between two others without
        // renumbering anything; renumbering on every save is what keeps them
        // from drifting into 11, 12, 13 after a few insertions.
        $position = 0;

        foreach ($rows as $row) {
            $position += self::POSITION_STEP;

            if ($row['id'] === null) {
                $added = $this->records->save(
                    $collection,
                    new Record(data: $row['data'], parentId: $parentId, position: $position),
                );

                $touched[] = [
                    'action' => 'added',
                    'child_id' => (int) $added->id,
                    'values' => $this->summarise($collection, $added->data),
                ];

                continue;
            }

            $child = $existing[$row['id']] ?? throw new \InvalidArgumentException(sprintf(
                'Row %d of collection "%s" does not belong to record %d.',
                $row['id'],
                $collection->getKey(),
                $parentId,
            ));

            $changed = $this->diff($collection, $child->data, $row['data']);
            $child->data = $row['data'];
            // Where it sits is not one of its values, so moving a row is not a
            // change to what it *is* — the timeline stays about the record
            // rather than about the arrangement of its lines.
            $child->position = $position;
            $this->records->save($collection, $child);
            $kept[$row['id']] = true;

            // An untouched row is not an event either.
            if ($changed !== []) {
                $touched[] = ['action' => 'updated', 'child_id' => (int) $child->id, 'changes' => $changed];
            }
        }

        foreach ($existing as $id => $child) {
            if (isset($kept[$id])) {
                continue;
            }

            $this->records->delete($collection, $child);
            $touched[] = [
                'action' => 'removed',
                'child_id' => $id,
                // What it was, so the entry still reads after the row is gone.
                'values' => $this->summarise($collection, $child->data),
            ];
        }

        return $touched;
    }

    /**
     * What changed between two versions of a shape's values.
     *
     * Compared in storage form, so a date submitted as a string and the same date
     * read back as an object are the same value rather than a change on every
     * save. Labels come from the definitions as they are *now*, which is when
     * this happened.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array<string, array{label: string, from: mixed, to: mixed}>
     */
    private function diff(ShapeDefinition $shape, array $before, array $after): array
    {
        $from = $this->records->storageValues($shape, $before);
        $to = $this->records->storageValues($shape, $after);

        $changes = [];

        foreach ($shape->getFields() as $field) {
            $key = $field->getKey();

            if ($from[$key] === $to[$key]) {
                continue;
            }

            $changes[$key] = ['label' => $field->getLabel(), 'from' => $from[$key], 'to' => $to[$key]];
        }

        return $changes;
    }

    /**
     * A row's values, for an entry about a row that was added or removed. Only
     * what was filled in — an address is described by the three lines somebody
     * typed, not by the two they left blank.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, array{label: string, value: mixed}>
     */
    private function summarise(ShapeDefinition $shape, array $data): array
    {
        $values = $this->records->storageValues($shape, $data);
        $summary = [];

        foreach ($shape->getFields() as $field) {
            if ($values[$field->getKey()] === null) {
                continue;
            }

            $summary[$field->getKey()] = ['label' => $field->getLabel(), 'value' => $values[$field->getKey()]];
        }

        return $summary;
    }
}

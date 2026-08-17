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

use Xivi\Core\Entity\ModuleDefinition;

/**
 * What a record's form starts with: its own values, and the rows of each
 * collection it owns.
 *
 * Lifted out of the controller in XIV-30. It belongs here rather than in the
 * application because it reads a shape and a record and nothing else — core
 * already owns everything it touches, and what a form starts with is a fact
 * about those two rather than about the request that happens to be asking.
 *
 * **It primes what those rows name** (XIV-54, and it took XIV-36 to need it).
 * The record page has always primed because it *renders* references; a form did
 * not have to, because a select renders a record's name out of a candidate list
 * it was going to read anyway. An autocompleting picker has no candidate list —
 * that is the point of it — so each row asks separately what the record it
 * points at is called, and five hundred order lines asked five hundred times.
 * Measured at 494 queries on a 500-line form, which would have been worse than
 * the number XIV-87 had just fixed.
 *
 * This is the place, for exactly the reason §5.3 gives: nothing during rendering
 * knows every id, and here the whole set is in hand. Priming stays an
 * optimisation and never a requirement — a caller that skipped it would be
 * slower and never wrong — so this is one call and no rule anybody has to
 * remember.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordFormData
{
    public function __construct(
        private RecordRepository $records,
        private RecordPrimer $primer,
    ) {
    }

    /**
     * @param array<string, list<array<string, mixed>>> $seeded rows a new record
     *                                                          starts with (XIV-19)
     *
     * @return array<string, mixed>
     */
    public function of(ModuleDefinition $definition, Record $record, array $seeded = []): array
    {
        $data = ['fields' => $record->data];

        // The record's own references too, so a form primes all of what it
        // draws rather than some of it — the same half-rule the record page
        // refused to leave behind (XIV-54).
        $this->primer->prime($definition, [$record]);

        foreach ($definition->getCollections() as $collection) {
            // Not on the form at all (XIV-16), so it needs no starting rows.
            if ($collection->isDerived()) {
                continue;
            }

            $children = $record->isNew() ? [] : $this->records->findChildren($collection, (int) $record->id);

            // Every row this collection will draw, before any of them is drawn.
            $this->primer->prime($collection, $children);

            $rows = array_map(
                static fn (Record $child): array => [
                    'id' => (string) $child->id,
                    'position' => $child->position,
                    'fields' => $child->data,
                ],
                $children,
            );

            // Rows copied from another record (XIV-19). They have no id — they
            // are new rows somebody is about to save — and they come before the
            // blank ones, so the form reads as the document it is becoming.
            foreach ($seeded[$collection->getKey()] ?? [] as $values) {
                $rows[] = ['id' => '', 'position' => null, 'fields' => $values];
            }

            // **No blank rows when a collection has kinds** (XIV-29). There used
            // to be one of each, because choosing which to fill in was how a kind
            // got chosen without scripting — four of them at the bottom of an
            // order, each showing different fields. A button per kind says the
            // same thing and shows nothing until it is asked to.
            //
            // A collection *without* kinds keeps its one blank row. One row to
            // type an address into is an affordance rather than a mess; four rows
            // showing four different sets of fields is the mess, and it is the
            // plural that made it one.
            if (!$collection->hasVariants()) {
                $rows[] = ['id' => '', 'position' => null, 'fields' => []];
            }

            $data['collections'][$collection->getKey()] = $rows;
        }

        return $data;
    }
}

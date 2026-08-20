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

namespace Xivi\Core\Export;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\HoldsSeveralValues;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * A module's records as a spreadsheet — for a backup, or for getting data out
 * of a system that is not this one and back into it later.
 *
 * **One sheet per shape**, mirroring the storage: a contact has many addresses,
 * so they cannot share a row (§5.1). The child sheet carries `parent_id`, which
 * is what lets the file be read back as the same structure it left as.
 *
 * **Headers are field keys, not labels.** A key is the one thing about a field
 * that cannot change — the editor refuses to rename one (§5.4) — so a file
 * exported today still matches its module after somebody relabels a column.
 * Import can be lenient and accept either; export should be stable.
 *
 * **Values are in storage form**, not display form: an ISO date rather than a
 * formatted one, a choice's stored value rather than its label, a reference's id
 * rather than the record's name. Anything else would be a file that reads nicely
 * and cannot be imported. A field naming several records is the one value that
 * is not a scalar, and it becomes one cell holding its ids separated by
 * {@see HoldsSeveralValues::SEPARATOR} (XIV-113).
 *
 * Variants need nothing special. Every field is a column, `kind` says which
 * apply, and the rest are blank for that row (§5.5).
 *
 * Nothing here knows what a contact is. The columns come from the customer's own
 * definitions, so a field added in the editor appears in the export with no code
 * changed — which is the same claim §5 makes about the form and the list.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordExporter
{
    /**
     * Records read per round trip. Large enough that a big export is not
     * thousands of queries, small enough that a page of hydrated records and
     * their children is not the whole table in memory at once.
     */
    public const int BATCH = 500;

    /** @var int<1, max> */
    private int $batch;

    /**
     * @param int $batch overridable so a test can exercise the paging without
     *                   writing five hundred records to prove it turns a page
     */
    public function __construct(
        private RecordRepository $records,
        int $batch = self::BATCH,
    ) {
        // Not decoration: a batch of zero asks for no rows, gets none, and never
        // reaches the end — the loop below would run until something gave out.
        $this->batch = max(1, $batch);
    }

    /**
     * Write the module's matching records to an xlsx file.
     *
     * Takes a path rather than a stream because openspout writes zip entries and
     * needs to seek; the caller streams the finished file and deletes it.
     *
     * The access restriction rides along with the query, and it has to: an export
     * is the fastest way there is to leave with records somebody was only ever
     * shown one page of. The collection sheets need no restriction of their own —
     * they are fetched for exactly the ids this wrote, so children follow their
     * parents in or out (§5.1).
     */
    public function toFile(ModuleDefinition $module, RecordQuery $query, RecordAccess $access, string $path): void
    {
        $writer = new Writer();
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName(self::sheetName($module->getKey()));
        $writer->addRow(Row::fromValues(['id', ...$module->getFieldKeys()]));

        // Ids collected as the sheet is written, so the collection sheets below
        // can be fetched for exactly the records that were exported — a filtered
        // export must not carry somebody else's addresses.
        $exported = [];

        foreach ($this->batches($module, $query, $access) as $batch) {
            foreach ($batch as $record) {
                $exported[] = (int) $record->id;
                $writer->addRow(Row::fromValues([$record->id, ...$this->valuesOf($module, $record)]));
            }
        }

        foreach ($module->getCollections() as $collection) {
            // Left out, because the import cannot read it back (XIV-16): a
            // derived collection is worked out on every save, so a sheet of it
            // would be a column of numbers that silently do nothing on the way
            // in. A file this export produces should be one this import accepts.
            if ($collection->isDerived()) {
                continue;
            }

            $writer->addNewSheetAndMakeItCurrent()->setName(self::sheetName($collection->getKey()));
            $writer->addRow(Row::fromValues(['id', 'parent_id', ...$collection->getFieldKeys()]));

            foreach (array_chunk($exported, $this->batch) as $parentIds) {
                foreach ($this->records->findChildrenOfAny($collection, $parentIds) as $child) {
                    $writer->addRow(Row::fromValues([
                        $child->id,
                        $child->parentId,
                        ...$this->valuesOf($collection, $child),
                    ]));
                }
            }
        }

        $writer->close();
    }

    /**
     * Pages of matching records, walked to the end.
     *
     * The query's own page is ignored on purpose: somebody exporting from page
     * three wants the whole filtered set, not the thirty rows in front of them.
     *
     * @return iterable<list<Record>>
     */
    private function batches(ModuleDefinition $module, RecordQuery $query, RecordAccess $access): iterable
    {
        $page = 1;

        do {
            $batch = $this->records->findBy($module, new RecordQuery(
                $query->filters,
                $query->sorts,
                $page,
                $this->batch,
            ), $access);

            if ($batch !== []) {
                yield $batch;
            }

            ++$page;
        } while (\count($batch) === $this->batch);
    }

    /**
     * The record's values in the shape's own field order, as stored.
     *
     * @return list<mixed>
     */
    private function valuesOf(ShapeDefinition $shape, Record $record): array
    {
        $stored = $this->records->storageValues($shape, $record->data);
        $values = [];

        foreach ($shape->getFieldKeys() as $key) {
            $value = $stored[$key] ?? null;

            // **A cell holds text, and a field may hold a list** (XIV-113). The
            // items go in separated by {@see HoldsSeveralValues::SEPARATOR},
            // which is the spelling the type reads back on the way in, so §5.6's
            // round trip still holds: export, re-import, nothing changes.
            //
            // Asked of the *value* rather than of the field's type, and that is
            // deliberate rather than lazy. This class has no field type registry
            // and wants none: it exists to know that a header is a key and a
            // value is storage form, and nothing else about what a field is. An
            // array is already the answer to the only question being asked here,
            // which is whether one cell has to hold more than one thing.
            $values[] = \is_array($value)
                ? implode(HoldsSeveralValues::SEPARATOR, array_map(strval(...), $value))
                : $value;
        }

        return $values;
    }

    /**
     * Excel refuses a few characters in a sheet name and truncates at 31, so a
     * long collection key would otherwise produce a file that will not open.
     *
     * Public because the importer has to look for exactly these names (§5.6).
     * One definition of the mangling, or a shape whose key is long enough to be
     * truncated would export to a sheet the import could not find.
     */
    public static function sheetName(string $key): string
    {
        return mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', $key), 0, 31);
    }
}

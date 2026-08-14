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

namespace Xivi\Core\Import;

use Doctrine\DBAL\Connection;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\Exception\ReaderException;
use OpenSpout\Reader\XLSX\Reader;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Export\RecordExporter;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Validation\RecordValidator;

/**
 * A spreadsheet back into a module — the other half of §5.6.
 *
 * The file the exporter writes, read the way it was written: one sheet per
 * shape, headers naming fields, `parent_id` tying a collection row to the record
 * that owns it. Import is the lenient end of that pair — a header may be a
 * field's key or its label, and a column the file does not have is a field the
 * import leaves alone.
 *
 * **All or nothing.** Every row is validated by the same validator the form uses
 * (§5), and one bad row refuses the file. Half an import is a state nobody can
 * reason about: the person who ran it cannot tell you what is in the database,
 * and neither can anybody else.
 *
 * **A check is the import, rolled back.** `check()` and `apply()` run the same
 * statements against the same connection; one commits and one does not. A dry
 * run down a separate code path would be a dry run of something else, and would
 * be trusted right up until the day the two disagreed. It is also the only way
 * the check can catch what only a write can — two rows in one file claiming the
 * same unique email are a collision the second write finds, because by then the
 * first one is really there.
 *
 * **Writes go through RecordWriter**, like everything else, so each imported row
 * gets its history entry attributed to whoever imported it (§5.2) for free.
 *
 * Nothing here knows what a contact is. Which columns exist, which are required,
 * what a value must look like and which variant a row belongs to all come from
 * the customer's own definitions.
 *
 * One known limit: the file is read into memory before anything is written,
 * because the sheets have to be cross-referenced before the first row can be
 * saved and a spreadsheet does not promise an order. That is fine for the files
 * a customer exports and edits, and would need revisiting for an import of
 * hundreds of thousands of rows.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordImporter
{
    public function __construct(
        private Connection $connection,
        private RecordRepository $records,
        // Never the repository: the writer owns the transaction and the history (§5.2).
        private RecordWriter $writer,
        private RecordValidator $validator,
    ) {
    }

    /** What the file would do, having actually done it and rolled it back. */
    public function check(ModuleDefinition $module, string $path, ?int $ownerId = null): ImportReport
    {
        return $this->run($module, $path, $ownerId, commit: false);
    }

    /** The same, kept — unless anything at all was wrong with the file. */
    public function apply(ModuleDefinition $module, string $path, ?int $ownerId = null): ImportReport
    {
        return $this->run($module, $path, $ownerId, commit: true);
    }

    private function run(ModuleDefinition $module, string $path, ?int $ownerId, bool $commit): ImportReport
    {
        try {
            $sheets = self::read($path);
        } catch (IOException|ReaderException $e) {
            // Not an exception the caller should have to handle: somebody
            // uploaded the wrong file, which is a sentence rather than a 500.
            return ImportReport::refused([new ImportProblem('file', null, sprintf(
                'This file could not be read as a spreadsheet (%s).',
                $e->getMessage(),
            ))]);
        }

        $plan = $this->plan($module, $sheets);

        if (!$plan->isClean()) {
            // Nothing below can mean anything while the columns are wrong, and a
            // list of every row failing because of one bad header is noise.
            return ImportReport::refused($plan->problems);
        }

        $this->connection->beginTransaction();

        try {
            $report = $this->write($module, $plan, $ownerId, $commit);
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        // The only difference between a check and an import, and the reason both
        // are worth the same amount of trust.
        if ($commit && $report->isClean()) {
            $this->connection->commit();
        } else {
            $this->connection->rollBack();
        }

        return $report;
    }

    /**
     * Everything the file says, before anything is written: which sheet is which,
     * what the columns mean, and which rows belong to which record.
     *
     * @param array<string, list<list<mixed>>> $sheets
     */
    private function plan(ModuleDefinition $module, array $sheets): ImportPlan
    {
        $problems = [];
        $moduleSheet = self::sheetKey($module->getKey());
        $rows = $sheets[$moduleSheet] ?? null;

        if ($rows === null) {
            return ImportPlan::refused([new ImportProblem('file', null, sprintf(
                'The file has no sheet named "%s". An export of this module is the shape it expects.',
                $moduleSheet,
            ))]);
        }

        $shapes = [$moduleSheet => $module];
        foreach ($module->getCollections() as $collection) {
            $shapes[self::sheetKey($collection->getKey())] = $collection;
        }

        $maps = [];
        foreach ($sheets as $name => $sheetRows) {
            $shape = $shapes[$name] ?? null;

            if ($shape === null) {
                // An empty sheet is what a spreadsheet program leaves lying
                // around. One with rows in it is data this import would drop, and
                // dropping data quietly is the thing all-or-nothing exists to
                // prevent.
                if (\count($sheetRows) > 1) {
                    $problems[] = new ImportProblem($name, null, 'This sheet matches no part of the module, and its rows would be ignored.');
                }

                continue;
            }

            $map = ColumnMap::build($shape, $sheetRows[0] ?? [], $name);
            $problems = [...$problems, ...$map->problems];

            if ($shape instanceof CollectionDefinition && $map->parentColumn === null && \count($sheetRows) > 1) {
                $problems[] = new ImportProblem($name, null, sprintf(
                    'A "%s" sheet needs a parent_id column saying which record each row belongs to.',
                    $shape->getLabel(),
                ));
            }

            $maps[$name] = $map;
        }

        if ($problems !== []) {
            return ImportPlan::refused($problems);
        }

        return new ImportPlan($moduleSheet, $shapes, $maps, $sheets, []);
    }

    /**
     * Apply the plan, collecting everything wrong with it as it goes.
     *
     * Rows are not abandoned at the first failure. Somebody fixing a spreadsheet
     * wants every line that needs fixing, not one per attempt — so a row that
     * cannot be written is recorded and skipped, and the transaction is thrown
     * away at the end regardless.
     */
    private function write(ModuleDefinition $module, ImportPlan $plan, ?int $ownerId, bool $commit): ImportReport
    {
        $problems = [];
        $created = 0;
        $updated = 0;
        $childrenWritten = 0;
        $childrenRemoved = 0;

        $moduleMap = $plan->maps[$plan->moduleSheet];
        $children = $this->childRows($module, $plan, $problems);
        $refs = [];

        foreach (\array_slice($plan->sheets[$plan->moduleSheet], 1) as $offset => $row) {
            $number = $offset + 2;
            $ref = self::ref($moduleMap->idOf($row));
            $values = self::cells($moduleMap->valuesOf($row));

            if (self::isBlank($values) && $ref === '') {
                // Trailing empty rows are how spreadsheets end. Skipped rather
                // than reported, or every file would arrive with a complaint.
                continue;
            }

            if ($ref !== '' && isset($refs[$ref])) {
                $problems[] = new ImportProblem($plan->moduleSheet, $number, sprintf('Id "%s" appears more than once.', $ref));

                continue;
            }

            $refs[$ref] = true;
            $record = $this->recordFor($module, $ref, $plan->moduleSheet, $number, $problems);

            if ($record === null) {
                continue;
            }

            $merged = [...$record->data, ...$values];
            $failed = $this->reportViolations(
                $module,
                $merged,
                $record->id,
                $plan->moduleSheet,
                $number,
                $problems,
            );

            $attached = [];

            // Driven by the collections whose sheet is present, not by the rows
            // this record happens to have: a record the sheet does not mention is
            // a record whose rows were all deleted from it, and emptying a
            // collection has to be something a file can say.
            foreach ($module->getCollections() as $collection) {
                $key = $collection->getKey();
                $sheet = self::sheetKey($key);

                // No sheet at all is the file saying nothing about this
                // collection, which is not the same as saying it is empty.
                if (!isset($plan->maps[$sheet])) {
                    continue;
                }

                $childRows = $children[$ref][$key] ?? [];

                $existing = [];
                foreach ($record->isNew() ? [] : $this->records->findChildren($collection, (int) $record->id) as $child) {
                    $existing[(int) $child->id] = $child;
                }

                $kept = [];

                foreach ($childRows as $childRow) {
                    $childData = self::cells($childRow['values']);

                    if ($childRow['id'] !== null && !isset($existing[$childRow['id']])) {
                        // Caught here so it reads as a line in a file rather than
                        // as the writer's InvalidArgumentException about a row
                        // belonging to another record.
                        $problems[] = new ImportProblem($sheet, $childRow['row'], sprintf(
                            'Row %d does not belong to record %s.',
                            $childRow['id'],
                            $ref,
                        ));
                        $failed = true;

                        continue;
                    }

                    $base = $childRow['id'] === null ? [] : $existing[$childRow['id']]->data;
                    $childMerged = [...$base, ...$childData];

                    $failed = $this->reportViolations(
                        $collection,
                        $childMerged,
                        $childRow['id'],
                        $sheet,
                        $childRow['row'],
                        $problems,
                    ) || $failed;

                    if ($childRow['id'] !== null) {
                        $kept[$childRow['id']] = true;
                    }

                    $attached[$key][] = ['id' => $childRow['id'], 'data' => $childMerged];
                    ++$childrenWritten;
                }

                // A sheet that is present speaks for the whole collection, so a
                // row it does not mention is a row somebody deleted. Counted
                // because it is the one thing an import destroys, and a check
                // that did not say so would be worth very little.
                $childrenRemoved += \count(array_diff_key($existing, $kept));

                $attached[$key] ??= [];
            }

            if ($failed) {
                continue;
            }

            $record->data = $merged;
            $record->ownerId ??= $ownerId;

            $isNew = $record->isNew();
            $this->writer->save($module, $record, $attached);

            if ($isNew) {
                ++$created;
            } else {
                ++$updated;
            }
        }

        // A collection row pointing at a record the file does not contain. It
        // cannot be attached — the parent is saved with its children in one call
        // — and attaching it by loading that record would let a two-line file
        // reach into anything. Named rather than dropped.
        foreach ($children as $ref => $byCollection) {
            if (isset($refs[$ref])) {
                continue;
            }

            foreach ($byCollection as $key => $childRows) {
                foreach ($childRows as $childRow) {
                    $problems[] = new ImportProblem(self::sheetKey($key), $childRow['row'], sprintf(
                        'No row of the %s sheet is called "%s". Put that same name in the id column of the row this belongs to.',
                        $plan->moduleSheet,
                        $ref,
                    ));
                }
            }
        }

        return new ImportReport(
            applied: $commit && $problems === [],
            created: $created,
            updated: $updated,
            childrenWritten: $childrenWritten,
            childrenRemoved: $childrenRemoved,
            problems: [...$plan->problems, ...$problems],
        );
    }

    /**
     * The record a row is about: an existing one it names, or a new one.
     *
     * A numeric id means an existing record, and one that names nothing is an
     * error rather than a quiet insert — a mistyped id would otherwise duplicate
     * the record it was meant to correct.
     *
     * Anything else non-empty is a name for a record this file is creating, which
     * is what lets a migration from another system bring children with it: the
     * addresses sheet points at "acme-1" and both arrive together. Numeric first,
     * so a spreadsheet turning `7` into `7.0` still updates record 7.
     *
     * @param list<ImportProblem> $problems
     */
    private function recordFor(
        ModuleDefinition $module,
        string $ref,
        string $sheet,
        int $number,
        array &$problems,
    ): ?Record {
        if ($ref === '' || !is_numeric($ref)) {
            return new Record();
        }

        $record = $this->records->find($module, (int) $ref);

        if ($record === null) {
            $problems[] = new ImportProblem($sheet, $number, sprintf(
                'There is no record with id %s. Leave the id empty to create a new one.',
                $ref,
            ));
        }

        return $record;
    }

    /**
     * The collection sheets, grouped by the record each row belongs to.
     *
     * Read before any record is written, because a row of the module sheet is
     * saved together with its children — one call, one transaction, one history
     * entry (§5.2).
     *
     * @param list<ImportProblem> $problems
     *
     * @return array<string, array<string, list<array{row: int, id: int|null, values: array<string, mixed>}>>>
     */
    private function childRows(ModuleDefinition $module, ImportPlan $plan, array &$problems): array
    {
        $grouped = [];

        foreach ($module->getCollections() as $collection) {
            $sheet = self::sheetKey($collection->getKey());
            $map = $plan->maps[$sheet] ?? null;

            // No sheet at all means the file says nothing about this collection,
            // which is not the same as saying it is empty. Leaving it out of the
            // save is what keeps a file of three columns from deleting every
            // address in the database.
            if ($map === null) {
                continue;
            }

            foreach (\array_slice($plan->sheets[$sheet], 1) as $offset => $row) {
                $number = $offset + 2;
                $values = $map->valuesOf($row);
                $parent = self::ref($map->parentOf($row));

                if ($parent === '' && self::isBlank($values)) {
                    continue;
                }

                if ($parent === '') {
                    $problems[] = new ImportProblem($sheet, $number, sprintf(
                        'This row names no parent record. Put the id of the %s it belongs to in parent_id.',
                        mb_strtolower($module->getLabel()),
                    ));

                    continue;
                }

                $id = $map->idOf($row);

                $grouped[$parent][$collection->getKey()][] = [
                    'row' => $number,
                    'id' => $id === '' || !is_numeric($id) ? null : (int) $id,
                    'values' => $values,
                ];
            }
        }

        return $grouped;
    }

    /**
     * Validate one shape's values and turn anything wrong into lines of a report.
     *
     * The same validator the form uses, which is the point: an import cannot
     * store what the form would have refused, and neither has to restate what a
     * field means.
     *
     * @param array<string, mixed> $values
     * @param list<ImportProblem>  $problems
     *
     * @return bool whether this row failed
     */
    private function reportViolations(
        ShapeDefinition $shape,
        array $values,
        ?int $recordId,
        string $sheet,
        int $number,
        array &$problems,
    ): bool {
        $violations = $this->validator->validate($shape, $values, $recordId);

        foreach ($violations as $violation) {
            $key = trim($violation->getPropertyPath(), '[]');
            $field = $shape->getField($key);

            $problems[] = new ImportProblem($sheet, $number, sprintf(
                '%s: %s',
                $field?->getLabel() ?? $key,
                $violation->getMessage(),
            ));
        }

        return \count($violations) > 0;
    }

    /**
     * How the two sheets name the same record.
     *
     * A spreadsheet is free to hand back `7` from one cell and `7.0` from
     * another, and an address whose parent did not match because of a decimal
     * point would be a mystery worth nobody's afternoon. Anything not numeric is
     * a name this file made up for a record it is creating, and is left as it is.
     */
    private static function ref(string $raw): string
    {
        return is_numeric($raw) ? (string) (int) $raw : $raw;
    }

    /**
     * A shape's sheet, named the way the exporter names it and matched the way
     * the reader lowercases it.
     */
    private static function sheetKey(string $shapeKey): string
    {
        return mb_strtolower(RecordExporter::sheetName($shapeKey));
    }

    /**
     * The file, as sheet name => rows.
     *
     * @return array<string, list<list<mixed>>>
     */
    private static function read(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_values($row->toArray());
                }

                // Matched case-insensitively: a sheet renamed by hand is still
                // the sheet it was, and refusing over a capital letter would be
                // pedantry rather than safety.
                $sheets[mb_strtolower(trim($sheet->getName()))] = $rows;
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /**
     * Cell values the field types can accept.
     *
     * A spreadsheet hands back what it thinks it holds, which is not always what
     * a field expects: a date cell is a DateTime even in a text column, where
     * casting it would be a fatal rather than a validation message, and a whole
     * number can arrive as 42.0. Everything else is passed through untouched —
     * "12abc" in a number column stays "12abc" so the validator can say so.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function cells(array $values): array
    {
        return array_map(static function (mixed $value): mixed {
            if ($value instanceof \DateTimeInterface) {
                // A plain date when there is no time on it, which is what a date
                // field stores and what a text field should read as.
                return $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
            }

            if (\is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < \PHP_INT_MAX) {
                return (int) $value;
            }

            return \is_string($value) ? trim($value) : $value;
        }, $values);
    }

    /** @param array<string, mixed> $values */
    private static function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}

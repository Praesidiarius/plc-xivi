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

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;

/**
 * Which column of a sheet holds what.
 *
 * Built from the header row against the customer's own definitions, so a field
 * added in the editor is importable with nothing changed — the same claim §5
 * makes about the form, the list and the export.
 *
 * **Keys or labels.** The export writes keys because they cannot change (§5.6);
 * the import accepts either, because the person editing the file sees the labels
 * and a header of "Email address" is not a mistake worth refusing. Lenient in,
 * stable out.
 *
 * A column matching nothing is a problem rather than a shrug: it is somebody's
 * data, and quietly dropping a column is the kind of silence that gets noticed
 * three months later.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ColumnMap
{
    /** The columns the engine owns; a field may not claim them. */
    public const string ID = 'id';
    public const string PARENT = 'parent_id';

    /**
     * @param array<int, FieldDefinition> $fields       column index => the field it fills
     * @param int|null                    $idColumn     where the record's own id is, if the file carries one
     * @param int|null                    $parentColumn where a collection row names its parent
     * @param list<ImportProblem>         $problems     headers that could not be placed
     */
    public function __construct(
        public array $fields,
        public ?int $idColumn,
        public ?int $parentColumn,
        public array $problems,
    ) {
    }

    /**
     * @param list<mixed> $header the sheet's first row
     */
    public static function build(ShapeDefinition $shape, array $header, string $sheet): self
    {
        $byKey = [];
        $byLabel = [];

        foreach ($shape->getFields() as $field) {
            $byKey[mb_strtolower($field->getKey())] = $field;
            // First one wins: two fields may legitimately share a label, and the
            // key is the unambiguous way to name the other one.
            $byLabel[mb_strtolower($field->getLabel())] ??= $field;
        }

        $fields = [];
        $seen = [];
        $problems = [];
        $idColumn = null;
        $parentColumn = null;

        foreach ($header as $index => $cell) {
            $name = mb_strtolower(trim((string) (\is_scalar($cell) ? $cell : '')));

            if ($name === '') {
                // A trailing empty column is what a spreadsheet leaves behind, not
                // something to complain about.
                continue;
            }

            // Reserved, and checked before the fields: a customer may well have
            // called something "id", and the engine's own column has to win or a
            // file could not name the record it updates.
            if ($name === self::ID) {
                $idColumn = $index;

                continue;
            }

            if ($name === self::PARENT) {
                $parentColumn = $index;

                continue;
            }

            $field = $byKey[$name] ?? $byLabel[$name] ?? null;

            if ($field === null) {
                $problems[] = new ImportProblem($sheet, null, 'import.problem.unknown_column', [
                    '%column%' => trim((string) (\is_scalar($cell) ? $cell : '')),
                    '%shape%' => $shape->getLabel(),
                ]);

                continue;
            }

            if (isset($seen[$field->getKey()])) {
                // Two columns feeding one field: one of them would silently win.
                $problems[] = new ImportProblem($sheet, null, 'import.problem.duplicate_column', [
                    '%field%' => $field->getLabel(),
                ]);

                continue;
            }

            $seen[$field->getKey()] = true;
            $fields[$index] = $field;
        }

        return new self($fields, $idColumn, $parentColumn, $problems);
    }

    /**
     * The row's values, keyed by field.
     *
     * Only the columns the file actually has. A field with no column is left
     * untouched on the record, so a file of three columns corrects three things
     * rather than blanking everything else — while a column that *is* present and
     * empty does clear its field, because that is what somebody deleting a cell
     * means.
     *
     * @param list<mixed> $row
     *
     * @return array<string, mixed>
     */
    public function valuesOf(array $row): array
    {
        $values = [];

        foreach ($this->fields as $index => $field) {
            $values[$field->getKey()] = $row[$index] ?? null;
        }

        return $values;
    }

    /**
     * The raw id cell, as written in the file.
     *
     * @param list<mixed> $row
     */
    public function idOf(array $row): string
    {
        return $this->cell($row, $this->idColumn);
    }

    /** @param list<mixed> $row */
    public function parentOf(array $row): string
    {
        return $this->cell($row, $this->parentColumn);
    }

    /** @param list<mixed> $row */
    private function cell(array $row, ?int $index): string
    {
        if ($index === null) {
            return '';
        }

        $value = $row[$index] ?? null;

        return \is_scalar($value) ? trim((string) $value) : '';
    }
}

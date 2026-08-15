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

namespace App\Record;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\InheritedValues;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordFormData;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Validation\RecordValidator;

/**
 * Everything between a submitted record form and a saved record.
 *
 * Found by the spike that moved saving into a Live Component (XIV-29's third
 * branch) and extracted on its own merits (XIV-30). Building a second caller is
 * what made the seam obvious, but the seam was always there: what a form
 * submission *means* is a fact about the shape and the values, and that a
 * controller was holding it is an accident of where it was first written, back
 * when there was exactly one caller.
 *
 * The same seam is what an API, a bulk-edit screen or an import wanting form
 * semantics would each need.
 *
 * Three steps, in order, and each is somebody's decision rather than plumbing:
 * which rows were really typed in, whether the whole thing is valid, and what
 * gets written.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordSubmission
{
    public function __construct(
        private RecordFormData $formData,
        private InheritedValues $inherited,
        private RecordValidator $validator,
        private RecordWriter $writer,
    ) {
    }

    /**
     * What the form starts with — its own values and the rows of each collection.
     *
     * @param array<string, list<array<string, mixed>>> $seeded rows a new record starts with (XIV-19)
     *
     * @return array<string, mixed>
     */
    public function initial(ModuleDefinition $definition, Record $record, array $seeded = []): array
    {
        return $this->formData->of($definition, $record, $seeded);
    }

    /**
     * The submitted collection rows, keyed by collection, carrying the position
     * they hold in the form so a violation can be put back on the row it came
     * from.
     *
     * Rows the person added and left completely empty are dropped rather than
     * validated: clicking "add address" and changing your mind is not an attempt
     * to save a blank address. The same rule means clearing every field of an
     * existing row deletes it, which is what emptying something out looks like
     * to anyone who is not thinking about databases.
     *
     * @param array<string, mixed> $submitted
     *
     * @return array<string, list<array{index: int, id: int|null, data: array<string, mixed>}>>
     */
    public function rows(ModuleDefinition $definition, array $submitted): array
    {
        /** @var array<string, array<int, array{id?: string|null, fields?: array<string, mixed>}>> $collections */
        $collections = $submitted['collections'] ?? [];
        $rows = [];

        foreach ($definition->getCollections() as $collection) {
            // A derived collection is not submitted and must not be listed here
            // as empty: the writer reads an empty list as "delete every row"
            // (XIV-16), and the deriver has not had its turn yet.
            if ($collection->isDerived()) {
                continue;
            }

            $rows[$collection->getKey()] = [];

            foreach ($collections[$collection->getKey()] ?? [] as $index => $entry) {
                $fields = $entry['fields'] ?? [];

                // **Nothing the engine put there counts as something typed.**
                // The kind does not (XIV-20): every blank row arrives carrying
                // one, so a row that only says what it *would* have been is
                // still a row nobody filled in, and without this a save would
                // mint an empty line of every kind the collection has.
                //
                // Nor does a derived value, for the same reason and a sharper
                // one: a disabled field keeps its value through a submit, so a
                // row somebody has emptied out still arrives carrying its line
                // total and, on a seeded row, the id of the row it came from
                // (XIV-19). Counting those, emptying a row stopped deleting it
                // and started failing validation instead.
                $typed = $fields;
                unset($typed[(string) $collection->getVariantField()]);

                foreach ($collection->getFields() as $field) {
                    if ($field->isDerived()) {
                        unset($typed[$field->getKey()]);
                    }
                }

                if (self::isBlank($typed)) {
                    continue;
                }

                $id = ($entry['id'] ?? '') === '' ? null : (int) $entry['id'];
                $rows[$collection->getKey()][] = [
                    'index' => $index,
                    'id' => $id,
                    // What the row takes from the record it points at, filled in
                    // once and never over something typed (XIV-18).
                    'data' => $this->inherited->fillIn($collection, $fields),
                    // A row nobody numbered goes to the end, which is where a
                    // blank row somebody has just filled in belongs.
                    'position' => ($entry['position'] ?? '') === '' ? \PHP_INT_MAX : (int) $entry['position'],
                ];
            }

            // Sorted by what the customer typed, and stable within it (XIV-21):
            // two rows sharing a number keep the order they were shown in, which
            // is the only answer that does not shuffle a list when somebody
            // numbers two rows the same by accident.
            usort(
                $rows[$collection->getKey()],
                static fn (array $a, array $b): int => [$a['position'], $a['index']] <=> [$b['position'], $b['index']],
            );
        }

        return $rows;
    }

    /** @param array<string, mixed> $fields */
    private static function isBlank(array $fields): bool
    {
        foreach ($fields as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the submission is valid, with anything wrong put back on the field
     * it came from.
     *
     * Each part is checked against its own definitions: the record against the
     * module's, every row against the collection's. The validator is handed a
     * shape and never asks which kind it is.
     *
     * @param FormInterface<array<string, mixed>>                                              $form
     * @param array<string, mixed>                                                             $fields
     * @param array<string, list<array{index: int, id: int|null, data: array<string, mixed>}>> $rows
     */
    public function validate(ModuleDefinition $definition, FormInterface $form, array $fields, array $rows, ?int $recordId): bool
    {
        $violations = $this->validator->validate($definition, $fields, $recordId);
        self::mapViolations($violations, $form->get('fields'));
        $valid = \count($violations) === 0;

        foreach ($rows as $key => $entries) {
            $collection = $definition->getCollection($key);
            \assert($collection !== null);

            foreach ($entries as $row) {
                $rowViolations = $this->validator->validate($collection, $row['data'], $row['id']);

                if (\count($rowViolations) > 0) {
                    $valid = false;
                    self::mapViolations(
                        $rowViolations,
                        $form->get('collections')->get($key)->get((string) $row['index'])->get('fields'),
                    );
                }
            }
        }

        return $valid;
    }

    /**
     * The record and its collections, written as one action.
     *
     * @param array<string, mixed>                                                             $fields
     * @param array<string, list<array{index: int, id: int|null, data: array<string, mixed>}>> $rows
     */
    public function save(ModuleDefinition $definition, Record $record, array $fields, array $rows, ?int $ownerId): Record
    {
        // Merged, not replaced. The form only carries this variant's fields, and
        // a value belonging to another variant is somebody's data — the same
        // reason removing a field leaves its values alone (§7.2).
        $record->data = [...$record->data, ...$fields];
        $record->ownerId ??= $ownerId;

        // One call, one transaction, one history entry — the record and its
        // collections are one action, not several.
        return $this->writer->save($definition, $record, array_map(
            static fn (array $entries): array => array_map(
                static fn (array $row): array => ['id' => $row['id'], 'data' => $row['data']],
                $entries,
            ),
            $rows,
        ));
    }

    /**
     * The engine validates an array, so its violations point at array keys
     * ("[email]"). Putting each one back on the field it came from is what lets a
     * single validator serve the form, an import, and whatever API comes later.
     *
     * @param FormInterface<array<string, mixed>> $form
     */
    public static function mapViolations(ConstraintViolationListInterface $violations, FormInterface $form): void
    {
        foreach ($violations as $violation) {
            $field = trim($violation->getPropertyPath(), '[]');

            $target = $form->has($field) ? $form->get($field) : $form;
            $target->addError(new FormError(
                // Unknown keys report against the form itself, where they are at
                // least visible, rather than being dropped for having no field.
                $form->has($field) ? (string) $violation->getMessage() : sprintf('%s: %s', $field, $violation->getMessage()),
            ));
        }
    }
}

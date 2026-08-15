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

namespace Xivi\Core\Document;

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Record\Record;

/**
 * What a template may write into itself, and what those markers are worth for
 * one record (XIV-4).
 *
 * **One place decides the key set**, because the reference list somebody writes
 * their Word document against and the values that fill it in have to be the same
 * words. Two functions computing them separately is a feature that works until a
 * field is renamed.
 *
 * The keys come from the customer's own definitions, so a field they added this
 * morning is a marker this afternoon and one they removed stops being offered —
 * the same claim the form and the list already make (§5).
 *
 * **Values are rendered through the field type**, not printed raw: a date reads
 * as a date and a price reads as "CHF 19.90", which is what a letter should say
 * and what `display()` already knows how to produce.
 *
 * Collections are deliberately not here yet. A contact's addresses are a
 * repeating block rather than a marker, which is a different feature in the
 * template as well as in the code.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DocumentMarkers
{
    /** Markers that are true of every record, whatever the module describes. */
    private const string ID = 'record_id';
    private const string CREATED = 'created_at';
    private const string UPDATED = 'updated_at';
    private const string TODAY = 'today';

    public function __construct(private FieldTypeRegistry $fieldTypes)
    {
    }

    /**
     * The reference list for one shape, in the order the fields are edited in.
     *
     * @param Record|null $example a record to show values from, when the list is
     *                             being read next to one
     *
     * @return list<DocumentMarker>
     */
    public function forShape(ModuleDefinition $module, ?string $variant, ?Record $example = null): array
    {
        $markers = [];

        foreach ($module->getFieldsFor($variant) as $field) {
            $markers[] = new DocumentMarker(
                $field->getKey(),
                $field->getLabel(),
                $example === null ? null : $this->render($field, $example->get($field->getKey())),
            );
        }

        // After the fields, because the fields are what somebody came to look
        // for; these are the ones every template ends up using once.
        $markers[] = new DocumentMarker(self::ID, 'Record number', $example?->id === null ? null : (string) $example->id);
        $markers[] = new DocumentMarker(self::CREATED, 'Created', self::date($example?->createdAt));
        $markers[] = new DocumentMarker(self::UPDATED, 'Changed', self::date($example?->updatedAt));
        $markers[] = new DocumentMarker(self::TODAY, 'Today', $example === null ? null : self::date(new \DateTimeImmutable()));

        return $markers;
    }

    /**
     * What each marker is worth for this record, ready to be substituted.
     *
     * Every key the reference list offers is present, empty ones included: a
     * marker left unfilled would otherwise print its own brackets into the
     * finished letter, which is worse than a blank.
     *
     * @return array<string, string>
     */
    public function dataFor(ModuleDefinition $module, Record $record): array
    {
        $data = [];

        foreach ($this->forShape($module, $module->variantOf($record->data), $record) as $marker) {
            $data[$marker->key] = $marker->example ?? '';
        }

        return $data;
    }

    private function render(FieldDefinition $field, mixed $value): string
    {
        return $this->fieldTypes->get($field->getType())->display($value, $field);
    }

    private static function date(?\DateTimeImmutable $when): ?string
    {
        return $when?->format('Y-m-d');
    }
}

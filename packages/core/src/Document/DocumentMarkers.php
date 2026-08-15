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

use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Record\Record;

/**
 * What a template may write into itself, and what those markers are worth for
 * one record (XIV-4).
 *
 * **Two kinds, and the difference is what they are about.** A record marker
 * describes the contact being written to, and there is one list per variant
 * because a person and a company hold different fields (§5.5). A general marker
 * describes the moment — today's date, who this installation is, who is
 * generating the document — and belongs under none of the variants, which is
 * exactly what put `[today]` in the wrong place when they were one list.
 *
 * **One place decides the key set**, because the reference list somebody writes
 * their Word document against and the values that fill it in have to be the same
 * words. Two functions computing them separately is a feature that works until a
 * field is renamed.
 *
 * The record keys come from the customer's own definitions, so a field they added
 * this morning is a marker this afternoon and one they removed stops being
 * offered — the same claim the form and the list already make (§5).
 *
 * **Values are rendered through the field type**, not printed raw: a date reads
 * as a date and a price as "CHF 19.90", which is what a letter should say and
 * what `display()` already knows how to produce.
 *
 * **A collection is a third kind** (XIV-17), and its markers are written
 * `[lines.description]` — the collection, a dot, the field. A row of a table
 * containing one repeats once per row of the collection ({@see RepeatingBlocks}),
 * so unlike the other two these are not substituted here: they name a *column*
 * rather than a value, and which value they are worth depends on which row is
 * being drawn.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DocumentMarkers
{
    /** Markers that are true of every record, whatever the module describes. */
    private const string ID = 'record_id';
    private const string CREATED = 'created_at';
    private const string UPDATED = 'updated_at';

    /** The one general marker core owns; the rest come from the application. */
    private const string TODAY = 'today';

    public function __construct(
        private FieldTypeRegistry $fieldTypes,
        private DocumentContext $context,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The reference list for one shape, in the order the fields are edited in.
     *
     * Only what the record is: the general markers are {@see self::general()},
     * because a date has no more to do with a person than with a company.
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
                // The field's own label: customer data, and never translated —
                // they may have renamed it, and overruling that would make the
                // rename screen a lie (§5.4).
                $field->getLabel(),
                $example === null ? null : $this->render($field, $example->get($field->getKey())),
            );
        }

        // After the fields, because the fields are what somebody came to look
        // for; these are the ones every template ends up using once. Still about
        // the record — its number, and when it was written.
        $markers[] = new DocumentMarker(self::ID, $this->label(self::ID), $example?->id === null ? null : (string) $example->id);
        $markers[] = new DocumentMarker(self::CREATED, $this->label(self::CREATED), self::date($example?->createdAt));
        $markers[] = new DocumentMarker(self::UPDATED, $this->label(self::UPDATED), self::date($example?->updatedAt));

        return $markers;
    }

    /**
     * The markers that are about the moment rather than the record.
     *
     * Their own section on the reference list and available in every template,
     * whatever module or variant it belongs to.
     *
     * @return list<DocumentMarker>
     */
    public function general(): array
    {
        return [
            new DocumentMarker(self::TODAY, $this->label(self::TODAY), self::date(new \DateTimeImmutable())),
            ...$this->context->markers(),
        ];
    }

    /**
     * The reference list for one collection, per kind of row (XIV-17).
     *
     * Keyed by variant, like the module's own lists are, and by the empty string
     * for a collection whose rows are all the same thing. The tokens carry the
     * variant — `[lines:article.description]` — because that is what decides
     * which template row a line is drawn with, and a list that showed the
     * variant-less form would be showing something that does something else.
     *
     * @return array<string, list<DocumentMarker>>
     */
    public function forCollection(CollectionDefinition $collection): array
    {
        $variants = array_keys($collection->getVariants());

        if ($variants === []) {
            return ['' => $this->markersOf($collection, null)];
        }

        $lists = [];

        foreach ($variants as $variant) {
            $lists[$variant] = $this->markersOf($collection, $variant);
        }

        return $lists;
    }

    /**
     * What one row of a collection is worth, ready to be substituted into a copy
     * of the block it is drawn with.
     *
     * Keyed by field, not by token: the token depends on how the template wrote
     * it — with a variant or without — and both mean this same value.
     *
     * @return array<string, string>
     */
    public function valuesOf(CollectionDefinition $collection, Record $row): array
    {
        $values = [];

        foreach ($collection->getFields() as $field) {
            $values[$field->getKey()] = $this->render($field, $row->get($field->getKey()));
        }

        return $values;
    }

    /**
     * What each marker is worth for this record, ready to be substituted.
     *
     * Every key either list offers is present, empty ones included: a marker left
     * unfilled would otherwise print its own brackets into the finished letter,
     * which is worse than a blank.
     *
     * The record's own markers are folded in last, so a customer who names a
     * field `today` gets their field. Their data outranks our vocabulary.
     *
     * @return array<string, string>
     */
    public function dataFor(ModuleDefinition $module, Record $record): array
    {
        $data = [];

        // Every collection marker, worth nothing (XIV-17). The ones inside a
        // repeating block are gone by the time this runs — a copy of the block
        // carries values rather than markers — so what is left is a collection
        // marker written somewhere no row could be drawn, and the same rule
        // applies to it as to any other unfilled marker: blank beats brackets.
        foreach ($module->getCollections() as $collection) {
            foreach (['', ...array_keys($collection->getVariants())] as $variant) {
                foreach ($collection->getFields() as $field) {
                    $data[self::collectionKey($collection->getKey(), $variant, $field->getKey())] = '';
                }
            }
        }

        foreach ([...$this->general(), ...$this->forShape($module, $module->variantOf($record->data), $record)] as $marker) {
            $data[$marker->key] = $marker->example ?? '';
        }

        return $data;
    }

    /** `lines.description`, or `lines:article.description` when it names a kind. */
    public static function collectionKey(string $collection, ?string $variant, string $field): string
    {
        return ($variant === null || $variant === '')
            ? sprintf('%s.%s', $collection, $field)
            : sprintf('%s:%s.%s', $collection, $variant, $field);
    }

    /** @return list<DocumentMarker> */
    private function markersOf(CollectionDefinition $collection, ?string $variant): array
    {
        $markers = [];

        foreach ($collection->getFieldsFor($variant) as $field) {
            $markers[] = new DocumentMarker(
                self::collectionKey($collection->getKey(), $variant, $field->getKey()),
                $field->getLabel(),
            );
        }

        return $markers;
    }

    private function render(FieldDefinition $field, mixed $value): string
    {
        return $this->fieldTypes->get($field->getType())->display($value, $field);
    }

    /** Core's own words, in the reader's language (XIV-8). */
    private function label(string $key): string
    {
        return $this->translator->trans('document.marker.' . $key, [], 'xivi');
    }

    private static function date(?\DateTimeImmutable $when): ?string
    {
        return $when?->format('Y-m-d');
    }
}

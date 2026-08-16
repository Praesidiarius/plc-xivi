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

namespace Xivi\Core\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Record\RecordRepository;

/**
 * Changing a customer's own field definitions (§5.4).
 *
 * The point of the whole engine, finally reachable without SQL: a customer adds
 * a field to their copy of a module and it appears in the form, the list, the
 * validation and the filter bar, because all four read the same rows.
 *
 * What it will not do is anything §7.2 has no answer for. There is no way to
 * change a field's type here, because there is no honest way to carry stored
 * values across one. Removing a field takes the definition and leaves the
 * values, which is the version of "delete" that cannot destroy anything.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class MetadataEditor
{
    /** Field keys are JSON object keys and column-ish identifiers; keep them boring. */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FieldTypeRegistry $fieldTypes,
        private RecordRepository $records,
        private MetadataCache $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws MetadataChangeRefused
     */
    public function addField(
        ShapeDefinition $shape,
        string $key,
        string $label,
        string $type,
        bool $required = false,
        bool $unique = false,
        bool $filterable = false,
        bool $listed = false,
        bool $title = false,
        array $options = [],
    ): FieldDefinition {
        $key = trim($key);

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw MetadataChangeRefused::badKey($key);
        }

        if ($shape->getField($key) !== null) {
            throw MetadataChangeRefused::keyTaken($key, $shape->getLabel());
        }

        // Fails here rather than at the first save, when the definition would
        // already exist and its records be unreadable.
        $this->fieldTypes->get($type);

        $field = new FieldDefinition(
            shape: $shape,
            key: $key,
            label: trim($label) === '' ? $key : trim($label),
            type: $type,
            required: $required,
            unique: $unique,
            filterable: $filterable,
            // Off unless asked for. A module's own fields are its designed shape
            // and appear by default; one added later is an addition, and an
            // addition should not silently rearrange a list somebody reads every
            // day. The editor offers the checkbox right beside the others.
            listed: $listed,
            title: $title,
            position: $shape->nextPosition(),
            // Not the module's: this one is the customer's, and that is what
            // makes it removable later.
            system: false,
        );
        // Through the same merge as an edit, so a setting somebody left blank is
        // an absent option rather than a null stored in the JSON.
        $field->setOptions(self::withOptions([], $options));

        $this->assertRecordsSurvive($shape, $field, $required, $unique);

        $this->entityManager->persist($field);
        $this->entityManager->flush();
        // What these queries would return has just changed (XIV-53). A page
        // still showing the old shape would look like the edit had failed.
        $this->cache->clear();

        return $field;
    }

    /**
     * Everything about a field that can change without touching what is stored.
     *
     * The type is not here, and neither is the key: a key is where the value
     * lives, so renaming one would orphan every value it names. Renaming is
     * `label`, which is the part people actually read.
     *
     * @param array<string, mixed> $options
     *
     * @throws MetadataChangeRefused
     */
    public function updateField(
        FieldDefinition $field,
        string $label,
        bool $required,
        bool $unique,
        bool $filterable,
        bool $listed,
        bool $title,
        int $position,
        array $options = [],
        ?int $width = null,
    ): void {
        $this->assertRecordsSurvive(
            $field->getShape(),
            $field,
            // Only a rule being switched *on* can invalidate anything; relaxing
            // one cannot.
            $required && !$field->isRequired(),
            $unique && !$field->isUnique(),
        );

        $field->setLabel(trim($label) === '' ? $field->getKey() : trim($label));
        $field->setRequired($required);
        $field->setUnique($unique);
        $field->setFilterable($filterable);
        $field->setListed($listed);
        $field->setTitle($title);
        $field->setPosition($position);
        // Null is "follow the field type" rather than "unchanged" (XIV-43) — the
        // editor always draws this control, so what it sends is always what the
        // customer meant, including empty.
        $field->setWidth($width);
        $field->setOptions(self::withOptions($field->getOptions(), $options));

        $this->entityManager->flush();
        $this->cache->clear();
    }

    /**
     * The options a field ends up with after a change to some of them (XIV-26).
     *
     * **What is not named is not touched**, and that is the whole point. Options
     * are where the declarative half of the engine lives — a choice field's
     * `choices`, a reference's `module`, an order line's `inherit`, a numbered
     * field's `sequence` — and the editor's form knows about three of them. It
     * used to replace the lot, so renaming a label wiped everything the form had
     * never heard of: a module's states, a shape's variants, a link's target.
     * None of it typeable back in, since the editor has no control for any of it.
     *
     * A caller that means to *clear* a setting says so by naming it with null.
     * The distinction between "not mentioned" and "mentioned as nothing" is what
     * lets a form both leave alone what it does not know and still empty the
     * boxes it draws.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $changes  null clears one; anything unnamed is left alone
     *
     * @return array<string, mixed>
     */
    private static function withOptions(array $existing, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($existing[$key]);

                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    /**
     * Remove a field's definition, and leave every stored value where it is.
     *
     * This is the answer to half of §7.2. Deleting the values as well would be
     * irreversible on a click; leaving them means the field can be added back
     * with the same key and the data returns. The editor says so plainly rather
     * than letting somebody assume the data is gone — which matters for a
     * product sold on data protection, and is why purging is a separate,
     * explicit operation and not a side effect of this one.
     *
     * @throws MetadataChangeRefused
     */
    /**
     * What a customer calls one of their own shapes (XIV-8).
     *
     * Refused when empty rather than silently kept, because a module with no
     * name is a blank tab nobody can click. There is nothing else to check: a
     * label names nothing but itself.
     *
     * @throws MetadataChangeRefused
     */
    public function renameShape(ShapeDefinition $shape, string $label): void
    {
        if (trim($label) === '') {
            throw MetadataChangeRefused::emptyLabel();
        }

        $shape->setLabel($label);
        $this->entityManager->flush();
        $this->cache->clear();
    }

    public function removeField(FieldDefinition $field): void
    {
        if ($field->isSystem()) {
            throw MetadataChangeRefused::systemField($field->getKey());
        }

        $field->getShape()->removeField($field);

        $this->entityManager->remove($field);
        $this->entityManager->flush();
        $this->cache->clear();
    }

    /** How many records still hold a value for this field. */
    public function recordsHolding(FieldDefinition $field): int
    {
        return $this->records->countWithValue($field->getShape(), $field);
    }

    /** @throws MetadataChangeRefused */
    private function assertRecordsSurvive(
        ShapeDefinition $shape,
        FieldDefinition $field,
        bool $required,
        bool $unique,
    ): void {
        if (!$required && !$unique) {
            return;
        }

        $violations = $this->records->countViolating($shape, $field, $required, $unique);

        if ($violations > 0) {
            throw MetadataChangeRefused::wouldInvalidateRecords($field->getKey(), $violations);
        }
    }
}

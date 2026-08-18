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
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\UniqueIndex;

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

    /**
     * How many shared values a refusal names before it gives up and says "…"
     * (XIV-109).
     *
     * Five is enough to recognise a pattern — three phone numbers that are all
     * `-` is a different problem from three that are real — and short enough to
     * fit in a message somebody reads rather than skims.
     */
    private const int DUPLICATES_NAMED = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FieldTypeRegistry $fieldTypes,
        private RecordRepository $records,
        private MetadataCache $cache,
        // The index that makes `unique` true rather than checked (XIV-109). It
        // has to be written by whatever writes the flag, or a definition and a
        // database disagree about a promise a customer is relying on.
        private UniqueIndex $uniqueIndexes,
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
        self::assertNumbersSomething($options);
        // Through the same merge as an edit, so a setting somebody left blank is
        // an absent option rather than a null stored in the JSON.
        $field->setOptions(self::withOptions([], $options));

        $this->assertRecordsSurvive($shape, $field, $required, $unique);

        $this->asOneChange(function () use ($shape, $field): void {
            $this->entityManager->persist($field);
            $this->entityManager->flush();
            // A field added with the same key as one that was removed brings
            // back the values that were left behind (§5.4), so even a brand-new
            // definition can meet a column that already holds something. The
            // count above has just refused the case where that collides; this
            // builds the index over whatever survived.
            $this->uniqueIndexes->follow($shape, $field);
        });

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
        self::assertNumbersSomething($options);
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

        $this->asOneChange(function () use ($field): void {
            $this->entityManager->flush();
            // Both directions, in the same transaction as the flag itself
            // (XIV-109): ticking the box builds the index, unticking it drops
            // the index, and a failure in either takes the definition back with
            // it. The alternative — flush, then index — leaves a field claiming
            // to be unique with nothing enforcing it, which is the state this
            // whole change exists to make impossible.
            $this->uniqueIndexes->follow($field->getShape(), $field);
        });

        $this->cache->clear();
    }

    /**
     * Whether a field is numbered at all, which is a different change from what
     * its numbers look like (XIV-91).
     *
     * {@see updateField()} is where a pattern is *edited*; this is where one
     * arrives or leaves. Its own method because it writes a second thing —
     * `derived` — and because that second thing is the point rather than
     * bookkeeping.
     *
     * **A numbered field is the engine's to fill and nobody's to type.** That is
     * what `derived` means (XIV-20): the form draws it and refuses to accept a
     * value for it, imports skip it, and the demo generator leaves it alone. A
     * field that were numbered *and* typeable would be a field where a person
     * can put `RE-2026-0007` into a record by hand at any moment, next to a
     * counter that has no way of hearing about it — which is exactly the
     * duplicate XIV-91 spent a column scan closing, reopened one save later and
     * permanently. So the two flags move together, and numbering is not a
     * setting that can be on while the field is still an ordinary text box.
     *
     * Turning it off puts the field back the way it was: typeable, and no longer
     * filled by anything. The numbers already on records stay — nothing here
     * reaches them, the same promise §5.10 has always made — and so does the
     * counter, which is what makes turning numbering back on later carry on
     * rather than start again over numbers that are already out there.
     *
     * The flag is cleared rather than left, and that is safe only because of
     * what may reach here: the editor offers this on a field that is numbered,
     * and offers numbering on a field that is *not* derived, so a field derived
     * by a module's own deriver — an order's total, an invoice's due date —
     * never becomes numbered and is never un-derived by this.
     *
     * @param ?string $pattern the numbering pattern, or null to stop numbering
     *
     * @throws MetadataChangeRefused when the pattern would number nothing
     */
    public function setNumbering(FieldDefinition $field, ?string $pattern): void
    {
        $change = [NumberFormat::OPTION => $pattern];

        self::assertNumbersSomething($change);

        $field->setOptions(self::withOptions($field->getOptions(), $change));
        $field->setDerived($pattern !== null);

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
     * A numbering pattern that arrives here has to number something (XIV-27).
     *
     * **On the write path rather than in the form**, and that is the whole point
     * of it being here. The metadata editor is not the only thing that calls
     * this — a module installer, an import, a console command and whatever comes
     * next all go through the same two methods — and a rule that lived in a
     * controller would be a rule that holds on one screen. What it protects
     * against is not a hostile caller but a quiet one: a pattern with no
     * `{number}` is *accepted* by every other part of the engine, as the field
     * simply not being a sequence, so nothing downstream would ever complain.
     *
     * Only a pattern that is *named* is checked. The merge below reads null as
     * "clear this option" and an absent key as "leave it alone", and neither of
     * those is a bad pattern — a form that draws no numbering control says
     * nothing about numbering, which is exactly what XIV-26 built.
     *
     * @param array<string, mixed> $options
     *
     * @throws MetadataChangeRefused
     */
    private static function assertNumbersSomething(array $options): void
    {
        if (!\array_key_exists(NumberFormat::OPTION, $options)) {
            return;
        }

        $pattern = $options[NumberFormat::OPTION];

        if ($pattern === null) {
            return;
        }

        if (!\is_string($pattern) || NumberFormat::parse($pattern) === null) {
            throw MetadataChangeRefused::patternNumbersNothing(\is_string($pattern) ? $pattern : '');
        }
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

        $shape = $field->getShape();
        $shape->removeField($field);

        $this->asOneChange(function () use ($shape, $field): void {
            $this->entityManager->remove($field);
            $this->entityManager->flush();
            // The values stay and the rule goes (XIV-109, §5.4). Keeping the
            // index would keep enforcing a promise nothing in the definitions
            // still makes — and would refuse a save on a field the customer can
            // no longer see, which is the least explainable error this engine
            // could produce. Adding the field back with the same key rebuilds
            // it, which is what makes removal reversible here too.
            $this->uniqueIndexes->drop($shape, $field);
        });

        $this->cache->clear();
    }

    /**
     * Mark a field unique, on its own (XIV-109).
     *
     * {@see self::updateField()} is a form's whole row and is the ordinary way
     * this flag moves. This exists for the caller that has one reason to want
     * uniqueness and no opinion at all about the label, the position or the
     * width — today that is {@see NumberingChange::start()}, where a document
     * number turning into a document number is precisely a promise that no two
     * records carry the same one. Routing that through `updateField()` would
     * mean reading seven settings off a definition and writing them straight
     * back, which reads as a change to all seven.
     *
     * **Already unique is a no-op rather than a rebuild.** The index is created
     * `IF NOT EXISTS`, so repeating it is harmless either way; returning early
     * is about the table lock a build takes, which is not worth paying to
     * confirm something that is already so.
     *
     * @throws MetadataChangeRefused when records already share a value, with the
     *                               count and the worst of the offending values
     *                               named — see {@see self::assertRecordsSurvive()}
     */
    public function makeUnique(FieldDefinition $field): void
    {
        if ($field->isUnique()) {
            return;
        }

        $shape = $field->getShape();
        $this->assertRecordsSurvive($shape, $field, required: false, unique: true);

        $field->setUnique(true);

        $this->asOneChange(function () use ($shape, $field): void {
            $this->entityManager->flush();
            $this->uniqueIndexes->follow($shape, $field);
        });

        $this->cache->clear();
    }

    /**
     * The values standing in the way of making this field unique (XIV-109).
     *
     * For the sentence a customer reads when the answer is no. Counting is not
     * enough on its own: "that rule would make 4 existing records invalid" is
     * true, and somebody then has to find four records among six hundred with
     * nothing to search for. These are what to search for.
     *
     * A read with no side effects, so a screen may call it to explain itself
     * before anybody has clicked anything.
     *
     * @return array<string, int> value => how many live records hold it, worst first
     */
    public function duplicatesIn(FieldDefinition $field, int $limit = 5): array
    {
        return $this->records->duplicateValues($field->getShape(), $field, $limit);
    }

    /**
     * A definition change and the schema change it implies, or neither
     * (XIV-109).
     *
     * The two writes are through different objects — the entity manager for the
     * row, the connection for the index — and they are one change. A flush that
     * lands while the `CREATE UNIQUE INDEX` after it fails would leave a field
     * marked unique with nothing enforcing it, which is exactly the state
     * XIV-109 exists to end; the reverse leaves an index enforcing a rule no
     * definition mentions, which is worse, because nothing in the editor can
     * then explain the refusal somebody meets.
     *
     * **The connection is taken from the entity manager rather than injected**,
     * and that is the point rather than a shortcut: it is by construction the
     * connection the flush will use, so the DDL below cannot end up in a
     * different transaction from the row it belongs with. An injected
     * `Connection` would be the same object today (config/services.yaml binds
     * both to the tenant's) and would be one wiring change away from not being.
     *
     * Postgres is transactional for DDL, which is what makes this possible at
     * all; on a database where it is not, this method would be a lie and the
     * ordering would have to be argued differently.
     *
     * @param \Closure():void $change
     */
    private function asOneChange(\Closure $change): void
    {
        $this->entityManager->getConnection()->transactional($change);
    }

    /** How many records still hold a value for this field. */
    public function recordsHolding(FieldDefinition $field): int
    {
        return $this->records->countWithValue($field->getShape(), $field);
    }

    /**
     * And how many do not, which is what a backfill would write to (XIV-91).
     *
     * Beside its opposite because they answer the same page: "143 records have
     * nothing in this field and would be given a number; 12 already hold one and
     * keep it" is one sentence made of two counts, and splitting them across two
     * services would be splitting a sentence.
     */
    public function recordsMissing(FieldDefinition $field): int
    {
        return $this->records->countWithoutValue($field->getShape(), $field);
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

        if ($violations === 0) {
            return;
        }

        // The unique half gets its own sentence when it is the half that failed
        // (XIV-109), because it is the only one with something to hand over: a
        // required field's problem is "these are empty" and there is nothing to
        // name. Asked only on the failing path, so the ordinary save of the
        // field editor still costs one count and no more.
        // One more than the message will print, so that the refusal can tell
        // "these five" from "at least these five" without a second query over
        // the whole column.
        $duplicates = $unique ? $this->duplicatesIn($field, self::DUPLICATES_NAMED + 1) : [];

        if ($duplicates !== []) {
            throw MetadataChangeRefused::valuesAreShared(
                $field->getKey(),
                // The unique half's own count rather than the sum above: a
                // caller switching both rules on at once would otherwise be told
                // that the empty records share a value with something.
                $this->records->countViolating($shape, $field, required: false, unique: true),
                $duplicates,
                self::DUPLICATES_NAMED,
            );
        }

        throw MetadataChangeRefused::wouldInvalidateRecords($field->getKey(), $violations);
    }
}

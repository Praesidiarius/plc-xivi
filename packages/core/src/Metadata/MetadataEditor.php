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
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\Enumerates;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\HoldsAFile;
use Xivi\Core\Field\HoldsSeveralValues;
use Xivi\Core\Field\NeedsAnAnswer;
use Xivi\Core\Field\PointsAtAList;
use Xivi\Core\Field\PointsAtAModule;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Module\AdditionKind;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Period\ExclusiveWithin;
use Xivi\Core\Query\Operator;
use Xivi\Core\Record\OverlapExclusion;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\UniqueIndex;
use Xivi\Core\ValueList\ValueLists;

/**
 * Changing a customer's own field definitions (§5.4).
 *
 * The point of the whole engine, finally reachable without SQL: a customer adds
 * a field to their copy of a module and it appears in the form, the list, the
 * validation and the filter bar, because all four read the same rows.
 *
 * What it will not do is anything §7.2 has no answer for. Removing a field takes
 * the definition and leaves the values, which is the version of "delete" that
 * cannot destroy anything.
 *
 * **Changing a field's type used to be on that list and no longer is**
 * ([XIV-146]). The old sentence said there was no honest way to carry stored
 * values across a type, and the honest way turned out to be to stop asking the
 * question in the abstract: {@see FieldTypeConversion} reads every value in the
 * column with the type it is moving to, refuses the whole change when any of
 * them cannot be read, and writes what it took away to each record's history
 * before the definition moves. {@see self::changeType()} is the half of that
 * which lives here, and it is the conversion's to call rather than anybody
 * else's.
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
        // What this customer actually has installed, for the one option whose
        // answer names another module (XIV-144). Read-only and through the same
        // cache every page reads, so checking a reference's target costs the
        // query the request had already made.
        private MetadataRepository $modules,
        // The index that makes `unique` true rather than checked (XIV-109). It
        // has to be written by whatever writes the flag, or a definition and a
        // database disagree about a promise a customer is relying on.
        private UniqueIndex $uniqueIndexes,
        // And the shared lists, for the option whose answer names one
        // ([XIV-127]). Beside the modules above and read through the same cache,
        // because the two are the same kind of question: an answer that names
        // something else in this tenant has to be checked against what is
        // actually there, on the write path, where the importer and the console
        // meet it too.
        private ValueLists $lists,
        // And the constraint that makes "these two cannot overlap" true rather
        // than checked (XIV-136). The same sentence, one level harder: a period
        // field naming what it is exclusive within is a promise, and a promise
        // with nothing enforcing it is the state both tickets exist to end.
        private OverlapExclusion $exclusions,
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
        bool $promoted = false,
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
            // Off unless asked for, on `listed`'s reason one step further up the
            // page ([XIV-173]): the record header is the most-read strip of the
            // application, and a field that put itself there on the way in would
            // be rearranging it for everybody who has the module.
            promoted: $promoted,
        );
        self::assertNumbersSomething($options);
        // Through the same merge as an edit, so a setting somebody left blank is
        // an absent option rather than a null stored in the JSON.
        $merged = self::withOptions([], $options);
        $field->setOptions($merged);

        // Every one of them, because there is nothing to leave alone yet
        // (XIV-144). A field arriving without the answer its type cannot work
        // without is the defect this ticket is about, and the moment to refuse it
        // is before it exists — after it exists there is a control in a form that
        // looks like it works.
        $this->assertNeedsAreAnswered($this->fieldTypes->get($type), $key, $merged);
        $this->assertTargetExists($this->fieldTypes->get($type), $key, $merged);
        $this->assertListExists($this->fieldTypes->get($type), $key, $merged);
        $this->assertScopeIsUsable($shape, $field, $merged);
        $this->assertUniqueIsAnswerable($this->fieldTypes->get($type), $key, $unique);
        $this->assertPromotionIsPossible($shape, $this->fieldTypes->get($type), $key, $promoted);
        $this->assertFileFitsThisShape($this->fieldTypes->get($type), $shape, $key);
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
            // And the same for periods, for the same reason — a re-added key can
            // meet values that were left behind, and those values can overlap
            // (XIV-136).
            $this->exclusions->follow($shape, $field);
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
     * **Every argument here is the value the field ends up with**, including the
     * ones a particular page did not draw a control for — the numbering page and
     * the options page both hand back the field as it already is and change one
     * thing. `$section` joins `$width` on those terms (XIV-119): null means
     * "in no section", not "leave it where it was", so a caller that forgets it
     * moves the field to the top of the form. That is the same trap `$width` has
     * had since XIV-43 and it is kept deliberately — a partial update would mean
     * this method could no longer be read as "the row of the form, applied".
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
        ?string $section = null,
        bool $promoted = false,
    ): void {
        self::assertNumbersSomething($options);
        $this->assertSectionExists($field->getShape(), $field->getKey(), $section);

        // Everything a change to a per-type option has to answer for before any
        // of it is written (XIV-144). Ahead of the flags on purpose: these are
        // the refusals that are about records nobody is looking at, and a save
        // that had already relabelled the field before refusing the list would
        // be the half-done state XIV-27 spent an ordering argument avoiding.
        $type = $this->fieldTypes->get($field->getType());
        $merged = self::withOptions($field->getOptions(), $options);
        // What the change would do to records and to the module, before whether
        // it leaves the field usable at all. The order decides which sentence
        // somebody reads when a save is both — ticking every option's remove box
        // is a list emptied *and* a list somebody's records are in, and "3
        // records are Pallet" is the one that says what to do about it.
        $this->assertOptionsSurvive($field, $type, $merged, $options);
        // The merge again, because the guard above may have cleared a setting
        // that no longer means anything — a variant belonging to the module this
        // field has just stopped pointing at.
        $merged = self::withOptions($field->getOptions(), $options);
        $this->assertNeedsAreAnswered($type, $field->getKey(), $merged, $options);
        // Before the flags below, on the same terms as the option guards above:
        // it is a refusal about records nobody is looking at, and a save that had
        // already relabelled the field before refusing the scope would be the
        // half-done state XIV-27 spent an ordering argument avoiding.
        $this->assertScopeIsUsable($field->getShape(), $field, $merged);
        $this->assertUniqueIsAnswerable($type, $field->getKey(), $unique);
        // And whether this field's values are the kind a header can draw as
        // chips ([XIV-173]). Asked of the type the field has *now*, which is the
        // only type it can have when this method runs: changing one goes through
        // {@see self::changeType()}, which clears the flag when the type being
        // moved to cannot honour it.
        $this->assertPromotionIsPossible($field->getShape(), $type, $field->getKey(), $promoted);

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
        // Which heading it is drawn under (XIV-119), on exactly the width's
        // terms above: null is "none" rather than "unchanged", and the key was
        // checked against this shape's own sections before anything was written.
        $field->setSection($section);
        // And whether its values are drawn at the top of the record page as well
        // ([XIV-173]), on the same terms again: false is "not in the header"
        // rather than "unchanged", so a page drawing no control for it hands
        // back what the field already had, exactly as it does for the width and
        // the section.
        $field->setPromoted($promoted);
        $field->setOptions($merged);

        $this->asOneChange(function () use ($field): void {
            $this->entityManager->flush();
            // Both directions, in the same transaction as the flag itself
            // (XIV-109): ticking the box builds the index, unticking it drops
            // the index, and a failure in either takes the definition back with
            // it. The alternative — flush, then index — leaves a field claiming
            // to be unique with nothing enforcing it, which is the state this
            // whole change exists to make impossible.
            $this->uniqueIndexes->follow($field->getShape(), $field);
            // Both directions again, for the scope (XIV-136). Naming one builds
            // the constraint, clearing it drops the constraint, and moving it
            // from one field to another is a drop and a build — which is why
            // `follow()` always drops first.
            $this->exclusions->follow($field->getShape(), $field);
        });

        $this->cache->clear();
    }

    /**
     * The definition half of a type change ([XIV-146], §7.2).
     *
     * **@internal to {@see FieldTypeConversion}, and that is a rule about data
     * rather than about layering.** By the time this runs, every value in the
     * column has already been rewritten in the new type's spelling, inside the
     * same transaction, and the record histories that say so have already been
     * written. On its own this method is the one thing §5.4 spent four
     * generations refusing: a column that means something new, full of values
     * spelled the old way, with nothing anywhere recording what they used to be.
     * The conversion is what makes it honest, so the conversion is what calls
     * it.
     *
     * **The options are replaced rather than merged**, which is the one place a
     * write in this class deliberately breaks XIV-26's rule that what a form
     * does not mention it does not touch. That rule is about an *edit*, where a
     * setting the page never drew is a setting somebody still means to have. A
     * type change is not an edit: `max_length` belongs to `text` and means
     * nothing to `phone`, a numbering pattern belongs to a field the engine
     * fills, and carrying either across would leave a definition holding answers
     * to questions its type does not ask. So the field arrives with the new
     * type's defaults, and the type's own settings are edited afterwards on the
     * field's own form, which is where they are edited every other day of the
     * week.
     *
     * The refusals are §5.4's existing ones, asked again because the field is
     * effectively a new one: a type that needs an answer nobody gave, a target
     * or a list that does not exist, a scope that cannot be honoured. What is
     * added is the two this change has of its own, and both are about the field
     * rather than about the data. A **derived** field is refused because the
     * engine fills it and its type is not the customer's to restate (§5.9,
     * §5.10): a numbered text field turned into a date would be a counter
     * writing values its own field cannot hold. And a change to the type the
     * field already has is refused rather than quietly done, because it would
     * rewrite a whole column to no purpose and the honest report of it is
     * "nothing to change".
     *
     * @param array<string, mixed> $options the options the field ends up with, in full
     *
     * @throws MetadataChangeRefused
     */
    public function changeType(FieldDefinition $field, string $type, array $options = []): void
    {
        if ($field->isDerived()) {
            throw MetadataChangeRefused::typeOfADerivedField($field->getKey());
        }

        if ($type === $field->getType()) {
            throw MetadataChangeRefused::typeUnchanged($field->getKey(), $type);
        }

        // Before anything is written, so an unknown type is a refusal rather
        // than a definition nothing can read.
        $target = $this->fieldTypes->get($type);
        $merged = self::withOptions([], $options);

        $shape = $field->getShape();
        $field->setType($type);
        $field->setOptions($merged);
        // A field promoted to the record header stops being promoted when its
        // new type has no set of values to draw as chips ([XIV-173]). Cleared
        // rather than refused, on the options' own reasoning three paragraphs
        // up: a type change is not an edit, and "you must untick a box on
        // another page before you may do this" would be a refusal about
        // presentation standing in the way of a change about data. Cleared
        // rather than left, because leaving it would put whatever the new type
        // displays, a paragraph or a date, in a pill beside the lifecycle state,
        // which is the state the refusal in `updateField()` exists to prevent
        // anybody reaching deliberately.
        if (!$target instanceof Enumerates) {
            $field->setPromoted(false);
        }

        $this->assertNeedsAreAnswered($target, $field->getKey(), $merged);
        $this->assertTargetExists($target, $field->getKey(), $merged);
        $this->assertListExists($target, $field->getKey(), $merged);
        $this->assertScopeIsUsable($shape, $field, $merged);

        $this->asOneChange(function () use ($shape, $field): void {
            $this->entityManager->flush();
            // The index is over `data ->> 'key'` and knows nothing about types,
            // so nothing here changes what it would enforce. What makes this
            // call necessary is the conversion above: it takes the index down
            // before rewriting the column, because a row converted early can
            // collide with a row not converted yet even when the state both of
            // them end up in is perfectly unique. This is where the promise
            // comes back, over values that have already been checked for
            // duplicates and refused if they had any.
            $this->uniqueIndexes->follow($shape, $field);
            // And the period constraint, one direction down and for the same
            // reason: a field that has just stopped being a period has no
            // business keeping the exclusion that made two of them refuse to
            // overlap.
            $this->exclusions->follow($shape, $field);
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
     * A type that cannot work without something, arriving without it (XIV-144).
     *
     * The engine's half of this ticket, and it is deliberately not the same
     * check the editor's form makes. The form draws a control and marks it
     * required, which is a promise to somebody using the page; this is the rule,
     * and it holds for the import, the console command and the form posted
     * around the page — the same division {@see self::assertNumbersSomething()}
     * makes and for the same reason.
     *
     * **On an edit it only judges what the caller named**, which is XIV-26's
     * contract holding even here: a row form that draws no options control says
     * nothing about options, and must be able to relabel a choice field whose
     * list is empty for reasons that predate this rule. What it will not let
     * anybody do is *empty* one — naming the option as null is a deliberate
     * clear, and clearing this particular option is what leaves the field
     * looking like it works.
     *
     * On an add there is nothing to leave alone, so every need is checked and an
     * option nobody mentioned is a missing answer rather than an absent opinion.
     *
     * **A need may now have more than one answer** ([XIV-127]), which changes
     * two words here and no arithmetic: a question is answered when *any* of its
     * options is, and it is judged at all when the caller named *any* of them. A
     * row form that sends `list` and not `choices` is therefore still saying
     * something about where a choice field's values come from, and is judged;
     * one that sends neither — the table's plain relabel — still says nothing
     * and is left alone, which is XIV-26's contract unchanged.
     *
     * @param array<string, mixed>  $merged the options the field will end up with
     * @param ?array<string, mixed> $named  what this caller mentioned, or null for "all of them"
     *
     * @throws MetadataChangeRefused
     */
    private function assertNeedsAreAnswered(FieldType $type, string $key, array $merged, ?array $named = null): void
    {
        if (!$type instanceof NeedsAnAnswer) {
            return;
        }

        foreach ($type->needs() as $answers) {
            if ($named !== null && array_intersect($answers, array_keys($named)) === []) {
                continue;
            }

            foreach ($answers as $option) {
                $answer = $merged[$option] ?? null;

                if ($answer !== null && $answer !== '' && $answer !== []) {
                    continue 2;
                }
            }

            throw MetadataChangeRefused::optionUnanswered($type->key(), $answers, $key);
        }
    }

    /**
     * A reference pointed at a module this customer has not got (XIV-144).
     *
     * Checked where the definition is written rather than where the select is
     * drawn, because a target that is not installed is exactly as broken as no
     * target at all: the picker finds nothing, every stored id renders as `#41`,
     * and nothing reports it. The select on the page is built from what is
     * installed, so this is the case that arrives by some other road.
     *
     * @param array<string, mixed> $merged
     *
     * @throws MetadataChangeRefused
     */
    private function assertTargetExists(FieldType $type, string $key, array $merged): void
    {
        if (!$type instanceof PointsAtAModule) {
            return;
        }

        $target = $merged[ReferenceFieldType::MODULE] ?? null;

        if (!\is_string($target) || $target === '' || $this->modules->isInstalled($target)) {
            return;
        }

        throw MetadataChangeRefused::unknownTarget($key, $target);
    }

    /**
     * A `choice` field pointed at a shared list this customer has not got
     * ([XIV-127]).
     *
     * {@see self::assertTargetExists()} for a list rather than a module, and
     * written beside it deliberately: the two are the same rule about two
     * different answers, and a reader who has understood one has understood
     * both. Checked on the write path for the same reason — the select on the
     * page is built from the lists that exist, so this is the case that arrives
     * from an import, a console command or a form posted around the page.
     *
     * @param array<string, mixed> $merged
     *
     * @throws MetadataChangeRefused
     */
    private function assertListExists(FieldType $type, string $key, array $merged): void
    {
        if (!$type instanceof PointsAtAList) {
            return;
        }

        $list = $merged[ChoiceFieldType::LIST] ?? null;

        if (!\is_string($list) || $list === '' || $this->lists->exists($list)) {
            return;
        }

        throw MetadataChangeRefused::unknownList($key, $list);
    }

    /**
     * What a change to a mandatory option would do to the records that already
     * depend on the answer (XIV-144).
     *
     * The two capabilities are asked separately because they are two different
     * questions, and generalising them would be inventing a language for
     * "changes to a list" to save one `if` — §5.4's argument about drawing
     * controls, applied to guarding them. What they have in common is only the
     * shape of the answer: **count first, refuse with the number, never fix
     * anything.**
     *
     * A module's own field is refused outright in both, which is §5.4's oldest
     * rule one level down: a module's fields are not the customer's to remove
     * because its code is written against them, and its `choice` field's options
     * and its reference's target are that same code's expectations written in
     * the definition.
     *
     * @param array<string, mixed> $merged  the options the field would end up with
     * @param array<string, mixed> $options what the caller named, which this may add to
     *
     * @throws MetadataChangeRefused
     */
    private function assertOptionsSurvive(FieldDefinition $field, FieldType $type, array $merged, array &$options): void
    {
        if ($type instanceof Enumerates && \array_key_exists(ChoiceFieldType::CHOICES, $options)) {
            $removed = array_values(array_diff(
                array_keys(ChoiceFieldType::choicesOf($field)),
                array_keys(ChoiceFieldType::clean($merged[ChoiceFieldType::CHOICES] ?? [])),
            ));

            if ($removed !== []) {
                if ($field->isSystem()) {
                    throw MetadataChangeRefused::optionsAreTheModules($field->getKey());
                }

                // **The type says which comparison finds a holder**, and the
                // editor deliberately does not know ([XIV-169]). For one option
                // that is equality against `data ->> 'key'`; for a field holding
                // several it is containment inside the array. Asked rather than
                // assumed because the wrong one here does not fail loudly: it
                // counts zero, the refusal below does not fire, and the option
                // comes off the list from under every record holding it. See
                // {@see Enumerates::findsHoldersBy()}.
                $held = $this->records->valueCountsAmong(
                    $field->getShape(),
                    $field,
                    $removed,
                    $type->findsHoldersBy(),
                );

                if ($held !== []) {
                    throw MetadataChangeRefused::optionsAreHeld($field->getKey(), $held);
                }
            }
        }

        if ($type instanceof PointsAtAList && \array_key_exists(ChoiceFieldType::LIST, $options)) {
            $this->assertValuesSurviveTheSource($field, $type, $merged);
        }

        if (!$type instanceof PointsAtAModule || !\array_key_exists(ReferenceFieldType::MODULE, $options)) {
            return;
        }

        $from = ReferenceFieldType::targetModule($field);
        $to = $merged[ReferenceFieldType::MODULE] ?? '';
        $to = \is_string($to) ? $to : '';

        if ($from === $to || $from === '' || $to === '') {
            // Three ways of not being a move. Unchanged is the ordinary case —
            // the row form sends the target on every save, exactly as it sends
            // the label. A field that pointed nowhere has no records that could
            // be pointing anywhere either, so setting a target on one is
            // finishing it rather than moving it. And an *emptied* target is not
            // a field pointed somewhere worse, it is a field pointed nowhere:
            // {@see self::assertNeedsAreAnswered()} refuses it a few lines later
            // with the sentence that says so, where a refusal from here would
            // have to name the module it was moving to and there is not one.
            return;
        }

        if ($field->isSystem()) {
            // Before the target is checked for existing, because "this field's
            // target is not yours to change" is true whatever it was going to be
            // changed to, and being told the other module is missing would send
            // somebody off to install it for nothing.
            throw MetadataChangeRefused::targetIsTheModules($field->getKey(), $from);
        }

        $this->assertTargetExists($type, $field->getKey(), $merged);

        $held = $this->records->countWithValue($field->getShape(), $field);

        if ($held > 0) {
            throw MetadataChangeRefused::targetIsHeld($field->getKey(), $from, $to, $held);
        }

        // The variant goes with the module it was a value of. Named as null
        // rather than left, because "not mentioned" would keep a narrowing that
        // now names a variant of a module this field no longer points at — which
        // is a picker that finds nothing, arrived at from the other direction.
        $options[ReferenceFieldType::VARIANT] = null;
    }

    /**
     * Where a `choice` field's values come from, changing under records that
     * already hold some ([XIV-127]).
     *
     * **The refusal that made a shared list an option on `choice` rather than a
     * field type of its own.** §5.21's argument against options in general is
     * that ticking one *reinterprets* everything already stored, at once, with
     * no migration and nothing on any screen to say it happened — and it is
     * exactly right about this option, because a field pointed at a list whose
     * entries do not include what its records hold is a field whose records
     * silently stop validating. So the reinterpretation is not permitted to be
     * silent: the values are counted against the new source first, and the ones
     * that would be left stranded are named with their counts.
     *
     * What survives the check is a change that reinterprets *nothing* — every
     * value means what it meant, because every value is still on the list. That
     * is the property §5.21 says an option must have to be an option, and it is
     * the property this one now has.
     *
     * **Both directions**, which is what makes it a rule rather than a
     * one-way gate: attaching a list, moving to another list, and taking the
     * field off a list back onto its own options are three spellings of the same
     * question. Only *changing* the source is checked; the row form sends this
     * option on every save exactly as it sends the target module, and a save
     * that leaves it where it was has nothing to reinterpret.
     *
     * A module's own field is refused outright and ahead of everything else, on
     * §5.4's oldest rule — see
     * {@see MetadataChangeRefused::listIsTheModules()} for why a module's own
     * `choice` field may not take its options from a list the customer keeps.
     *
     * @param array<string, mixed> $merged the options the field would end up with
     *
     * @throws MetadataChangeRefused
     */
    private function assertValuesSurviveTheSource(FieldDefinition $field, PointsAtAList $type, array $merged): void
    {
        $from = ChoiceFieldType::listKeyOf($field);
        $to = $merged[ChoiceFieldType::LIST] ?? '';
        $to = \is_string($to) ? $to : '';

        if ($from === $to) {
            return;
        }

        if ($field->isSystem()) {
            throw MetadataChangeRefused::listIsTheModules($field->getKey());
        }

        if ($to === '') {
            // Off the list and back onto the field's own options. The survivors
            // are whatever the same save leaves in `choices`, which for a field
            // that had its own list before it was attached is the list it had —
            // XIV-26's "what the form does not mention it does not touch" is
            // what makes going back possible at all.
            $held = $this->records->valueCountsExcept(
                $field->getShape(),
                $field,
                array_map(strval(...), array_keys(ChoiceFieldType::clean($merged[ChoiceFieldType::CHOICES] ?? []))),
                self::DUPLICATES_NAMED,
                $type->findsHoldersBy(),
            );

            if ($held !== []) {
                throw MetadataChangeRefused::valuesAreNotAmongItsOptions($field->getKey(), $held);
            }

            return;
        }

        // Before the count, because "there is no such list" is true whatever the
        // records hold, and counting against a list that is not there would be
        // counting against nothing and refusing with every value in the column.
        $this->assertListExists($type, $field->getKey(), $merged);

        $held = $this->records->valueCountsExcept(
            $field->getShape(),
            $field,
            $this->lists->get($to)->values(),
            self::DUPLICATES_NAMED,
            $type->findsHoldersBy(),
        );

        if ($held !== []) {
            throw MetadataChangeRefused::valuesAreNotOnTheList($field->getKey(), $to, $held);
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
    // ↑ belongs to removeField() below; renameShape() sits between them.

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

    /**
     * A heading on a module's form, named by the customer (XIV-119).
     *
     * The key is derived from the name once and never again, which is XIV-144's
     * decision for a choice field's options one level up ({@see Section::keyFor()}):
     * the fields carry the key, everybody reads the label, and renaming is
     * therefore free by construction. The derivation is fed what the module
     * already has, so a second "Billing" gets its own key rather than absorbing
     * the first one's fields.
     *
     * It lands at the end, in tens, because that is where a new field lands and
     * for the same reason — something added should appear where it was added.
     *
     * @throws MetadataChangeRefused
     */
    public function addSection(ModuleDefinition $module, string $label): Section
    {
        $label = trim($label);

        if ($label === '') {
            throw MetadataChangeRefused::sectionNeedsAName();
        }

        $taken = [];

        foreach ($module->getSections() as $existing) {
            $taken[$existing->key] = $existing;
        }

        $section = new Section(Section::keyFor($label, $taken), $label, $module->nextSectionPosition());
        $module->setSections([...array_values($taken), $section]);

        $this->entityManager->flush();
        $this->cache->clear();

        return $section;
    }

    /**
     * Renaming and reordering them, which is one save because it is one page.
     *
     * **Only sections the module already has are touched, and a section missing
     * from the list is left exactly where it is** — the opposite contract to a
     * choice field's options, deliberately. There, absence had to mean removal
     * or a removal could never be expressed; here removal is
     * {@see self::removeSection()}, a page of its own with a sentence about what
     * happens to the fields, so absence can safely mean "not mentioned". A key
     * that is not on this module is ignored rather than invented: a section made
     * by a hand-edited form would be a heading nobody asked for.
     *
     * @param array<string, string> $labels    key => the name it should now have
     * @param array<string, int>    $positions key => where it should sit
     */
    public function updateSections(ModuleDefinition $module, array $labels, array $positions): void
    {
        $sections = [];

        foreach ($module->getSections() as $section) {
            $label = trim((string) ($labels[$section->key] ?? ''));
            // A name emptied out keeps the old one, which is what a field's label
            // already does: a blank heading is a rule nobody meant to write, and
            // the operation with consequences attached is on another page.
            $section = $label === '' ? $section : $section->renamedTo($label);
            $sections[] = \array_key_exists($section->key, $positions)
                ? $section->movedTo($positions[$section->key])
                : $section;
        }

        $module->setSections($sections);

        $this->entityManager->flush();
        $this->cache->clear();
    }

    /**
     * Taking a heading away, and leaving every field that was under it
     * (XIV-119).
     *
     * **This is §5.4's removal rule one level up.** Removing a field takes the
     * definition and leaves the values; removing a section takes the heading and
     * leaves the fields, with their order, their widths, their rules and their
     * data untouched. They are drawn at the top of the form again, where they
     * were before anybody made a section, and putting them back is one select
     * per field. Nothing about any record changes, because nothing about any
     * record ever depended on this.
     *
     * The fields are cleared **in the same transaction** as the heading. Leaving
     * them naming a section that no longer exists would read as ungrouped
     * anyway — {@see ModuleDefinition::getFieldGroupsFor()} is deliberate about
     * that — but a definition that is merely interpreted correctly is not the
     * same as a definition that is right, and an export of one would carry a
     * word with nothing behind it.
     */
    public function removeSection(ModuleDefinition $module, string $key): void
    {
        if (!$module->hasSection($key)) {
            throw MetadataChangeRefused::unknownSection($module->getKey(), $key);
        }

        $module->setSections(array_values(array_filter(
            $module->getSections(),
            static fn (Section $section): bool => $section->key !== $key,
        )));

        foreach ($module->getFields() as $field) {
            if ($field->getSection() === $key) {
                $field->setSection(null);
            }
        }

        $this->asOneChange(function (): void {
            $this->entityManager->flush();
        });

        $this->cache->clear();
    }

    /** How many fields would come out of a section if it went (XIV-119). */
    public function fieldsIn(ModuleDefinition $module, string $key): int
    {
        $count = 0;

        foreach ($module->getFields() as $field) {
            if ($field->getSection() === $key) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * A field may only be put in a heading its own shape has (XIV-119).
     *
     * On the write path rather than in the controller, so the console and an
     * import meet it too — the rule every other refusal in this class follows,
     * and the reason a second copy in a controller is the copy that gets
     * forgotten.
     *
     * @throws MetadataChangeRefused
     */
    private function assertSectionExists(ShapeDefinition $shape, string $field, ?string $section): void
    {
        if ($section === null || trim($section) === '') {
            return;
        }

        if (!$shape instanceof ModuleDefinition) {
            throw MetadataChangeRefused::sectionsAreForModules($shape->getLabel());
        }

        if (!$shape->hasSection(trim($section))) {
            throw MetadataChangeRefused::unknownSection($field, trim($section));
        }
    }

    public function removeField(FieldDefinition $field): void
    {
        if ($field->isSystem()) {
            throw MetadataChangeRefused::systemField($field->getKey());
        }

        $shape = $field->getShape();
        $shape->removeField($field);

        // And remember that they did not want it (XIV-70).
        //
        // **This is the moment the decision is unambiguous, and the only one.**
        // Once this row is gone, a field somebody deleted on purpose and a field
        // they never had look identical — the definition is the only difference
        // between them and it is what is being removed. So the upgrade screen,
        // which offers what the blueprint has and this shape has not, would
        // otherwise re-offer this field for ever, asking somebody the same
        // question they have just answered by hand.
        //
        // Recorded whatever the key is, rather than only for keys the blueprint
        // happens to declare. This class has no blueprint to check against and
        // should not grow one: it edits definitions and knows nothing about
        // which build is deployed. Recording a customer's own `nickname` costs a
        // string and means that a future module declaring a `nickname` does not
        // reopen a question this customer already closed — which is the right
        // answer anyway, and the dismissed list on the upgrade screen is where
        // somebody takes it back.
        //
        // Inside the same transaction as the removal below (XIV-109 put one
        // there), so a decline cannot survive a removal that rolled back.
        $shape->decline(AdditionKind::Field, $field->getKey());

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
            // The same for the period's constraint (XIV-136), and it matters
            // twice over: a removed period field would otherwise go on refusing
            // bookings nobody can see a reason for, and a removed *scope* field
            // leaves a constraint over a key that is no longer in any payload —
            // which quietly becomes "one booking at a time, ever".
            $this->exclusions->drop($shape, $field);
            $this->rebuildPeriodsScopedBy($shape, $field);
        });

        $this->cache->clear();
    }

    /**
     * Remember that a customer does not want something their blueprint offers
     * (XIV-70).
     *
     * Here rather than on the entity's own doorstep for the reason every other
     * write in this class is here: definitions are read through a cache with a
     * stated lifetime (XIV-53), and a page still showing an offer somebody has
     * just dismissed would look like the dismissal had failed. One place that
     * writes definitions, one place that empties the cache.
     */
    public function declineAddition(ShapeDefinition $shape, AdditionKind $kind, string $key): void
    {
        $shape->decline($kind, $key);

        $this->entityManager->flush();
        $this->cache->clear();
    }

    /** And take it back, which has to be possible or the first answer is a trap. */
    public function restoreAddition(ShapeDefinition $shape, AdditionKind $kind, string $key): void
    {
        $shape->restore($kind, $key);

        $this->entityManager->flush();
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
        $this->assertUniqueIsAnswerable($this->fieldTypes->get($field->getType()), $field->getKey(), unique: true);
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
     * How many records hold each of a choice field's own options (XIV-144).
     *
     * Beside the options on the page that edits them, because the count is the
     * whole of what makes one of them removable and the other not. Somebody
     * looking at "Pallet — 3 records" and "Crate — none" can plan the change;
     * somebody who has to try it and read a refusal is being taught the rule one
     * failure at a time.
     *
     * Options nothing holds are absent rather than zero, which is the shape
     * {@see RecordRepository::valueCountsAmong()} answers in and the shape a
     * template wants: `held[value] ?? 0`.
     *
     * @return array<string, int> value => how many live records hold it
     */
    public function valuesHeldBy(FieldDefinition $field): array
    {
        $type = $this->fieldTypes->get($field->getType());

        return $this->records->valueCountsAmong(
            $field->getShape(),
            $field,
            array_map(strval(...), array_keys(ChoiceFieldType::choicesOf($field))),
            // The same question the refusal asks, so the number printed beside an
            // option and the number that decides whether it may go are the same
            // number ([XIV-169]). A type that does not enumerate has no options
            // for this page to count, and `choicesOf()` gives it none.
            $type instanceof Enumerates ? $type->findsHoldersBy() : Operator::Equals,
        );
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
    /**
     * What a period is exclusive within has to be something, and the records
     * already there have to survive it (XIV-136).
     *
     * Four refusals, in the order somebody meets them, and the ordering is the
     * usual one: the questions that are about the *shape* first, because they are
     * true whatever is stored, and the one that costs a query over the records
     * last.
     *
     * **On the write path rather than in the form**, like every other guard in
     * this class: the editor draws a select of the shape's own fields, so a
     * customer using the page meets a control that cannot say anything wrong —
     * and an import, a console command or a form posted around the page meets
     * this.
     *
     * **Only when the caller named the option**, which is XIV-26's contract:
     * a form that draws no scope control says nothing about scopes, so an
     * ordinary relabelling of a booking's period does not re-run a self-join over
     * every record.
     *
     * @param array<string, mixed> $merged the options the field will end up with
     *
     * @throws MetadataChangeRefused
     */
    private function assertScopeIsUsable(ShapeDefinition $shape, FieldDefinition $field, array $merged): void
    {
        $scope = $merged[ExclusiveWithin::OPTION] ?? null;

        if (!\is_string($scope) || trim($scope) === '') {
            return;
        }

        $scope = trim($scope);

        if (!$shape instanceof ModuleDefinition) {
            throw MetadataChangeRefused::scopeOnACollection($field->getKey(), $shape->getLabel());
        }

        if ($scope === $field->getKey() || $shape->getField($scope) === null) {
            throw MetadataChangeRefused::scopeIsNotAField($field->getKey(), $scope);
        }

        // One more than the message prints, so the refusal can tell "these five"
        // from "at least these five" without a second query — the same trick
        // {@see MetadataChangeRefused::valuesAreShared()} plays with duplicates.
        $conflicts = $this->exclusions->conflicts($shape, $field, $scope, self::DUPLICATES_NAMED + 1);

        if ($conflicts !== []) {
            throw MetadataChangeRefused::periodsAlreadyOverlap(
                $field->getKey(),
                $scope,
                $conflicts,
                self::DUPLICATES_NAMED,
            );
        }
    }

    /**
     * `unique` on a type that holds several values, refused (XIV-113).
     *
     * Before {@see self::assertRecordsSurvive()} rather than inside it, and the
     * order is the point: that method counts the records a rule would invalidate,
     * and there are none: a field holding lists has no duplicates in the sense
     * the index means, so the count would come back zero and the flag would be
     * written. This is a refusal about the rule itself rather than about the
     * data, so it is asked before anybody goes looking at rows.
     *
     * Keyed on the capability, never on the type's name, so the second type to
     * store a list inherits the refusal instead of quietly getting an index that
     * enforces the wrong thing.
     */
    private function assertUniqueIsAnswerable(FieldType $type, string $key, bool $unique): void
    {
        if ($unique && $type instanceof HoldsSeveralValues) {
            throw MetadataChangeRefused::uniqueHoldsSeveralValues($key, $type->key());
        }
    }

    /**
     * Whether this field's values are the kind the record header can draw
     * ([XIV-173]).
     *
     * Two refusals, and both are about what the header *is* rather than about
     * taste. It is a strip of chips beside the module label and the lifecycle
     * state, so it wants values out of a closed set the customer keeps
     * ({@see Enumerates}), and a paragraph of markdown in a pill is not a
     * smaller version of a tag, it is a broken page. And it belongs to a record, so a
     * collection's fields are out: those describe a *row*, and a record with
     * twelve order lines has twelve answers to "which article", none of which is
     * the record's.
     *
     * Keyed on the capability rather than on the two type names that satisfy it
     * today, which is the same rule `unique` and files are refused by: the next
     * enumerating type inherits the answer instead of discovering it.
     *
     * Only ever a refusal when the flag is being *asked for*. A field that is
     * already promoted and is being saved with something else changed cannot
     * reach either branch with false, so relaxing is free, exactly as it is for
     * `required` and `unique`.
     */
    private function assertPromotionIsPossible(ShapeDefinition $shape, FieldType $type, string $key, bool $promoted): void
    {
        if (!$promoted) {
            return;
        }

        if (!$shape instanceof ModuleDefinition) {
            throw MetadataChangeRefused::promotionIsForModules($shape->getLabel());
        }

        if (!$type instanceof Enumerates) {
            throw MetadataChangeRefused::promotionNeedsAValueSet($key, $type->key());
        }
    }

    /**
     * A file on a collection row, refused ([XIV-115]).
     *
     * Not because bytes on a row are a strange thing to want: a delivery note per
     * line is a perfectly good idea. It is refused because **a row has no
     * address**. A download is checked against the record it hangs off and is
     * reached at `/m/{module}/{id}/file/{field}` (§8.4), and a collection row is
     * identified by its parent plus its own id, which that route has nowhere to
     * put. Adding the field first and the route later would mean a field somebody
     * can fill in and nobody can read back, which is §8.3.1's defect in its
     * worst form: a control that takes a customer's contract and loses it.
     *
     * Keyed on the capability rather than on the type's name, so a second type
     * holding bytes inherits the refusal instead of discovering it.
     */
    private function assertFileFitsThisShape(FieldType $type, ShapeDefinition $shape, string $key): void
    {
        if ($type instanceof HoldsAFile && $shape instanceof CollectionDefinition) {
            throw MetadataChangeRefused::fileOnACollection($key, $shape->getLabel());
        }
    }

    /**
     * Every period constraint that was about the field just removed, brought back
     * into line with the definitions (XIV-136).
     *
     * A scope field is an ordinary field and can be removed like any other, and
     * the constraint over it is not the customer's to see: it names two keys in a
     * JSONB payload, one of which has just stopped being a field. Left standing it
     * would keep comparing `data ->> 'room'`, which no page writes any more, so
     * every record would be in the same scope as every other one and the module
     * would quietly become "one booking at a time, ever".
     *
     * `follow()` drops before it decides, and it decides by asking the shape
     * whether the scope field exists — which, by this point, it does not. So this
     * is a rebuild that correctly builds nothing, rather than a special case that
     * has to know it is a removal.
     */
    private function rebuildPeriodsScopedBy(ShapeDefinition $shape, FieldDefinition $removed): void
    {
        foreach ($shape->getFields() as $field) {
            if (ExclusiveWithin::of($field) === $removed->getKey()) {
                $this->exclusions->follow($shape, $field);
            }
        }
    }

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

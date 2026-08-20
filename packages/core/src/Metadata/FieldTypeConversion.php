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

use Doctrine\DBAL\Connection;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Event\RecordChanged;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Record\DerivedValues;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordChanges;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Record\UniqueIndex;

/**
 * Changing a field's type on a tenant that already has records ([XIV-146],
 * §7.2).
 *
 * §5.4's oldest refusal, lifted: the editor used to say no to this outright,
 * because stored values may not survive a new type. That was the right answer to
 * the wrong question. **Whether a value survives is not a property of the pair
 * of types, it is a property of the value**, and the values belong to a
 * particular customer. `+41 79 123 45 67` is a phone number and `ask reception`
 * is not, and which of those a tenant's contacts hold is something only their
 * database knows. So there is no table anywhere in this class of which type may
 * become which, and there is deliberately no room for one to grow: every value
 * is read by the type it is moving to, one row at a time, and the answer comes
 * out of that reading.
 *
 * ### The dry run is the same code as the run
 *
 * {@see self::plan()} converts every value and writes nothing;
 * {@see self::convert()} calls it again inside its own transaction and then
 * writes. The figures on the confirmation page are therefore counted rather than
 * estimated, and what somebody agreed to is what happens, up to records saved
 * between the two, which the second computation catches because it is the same
 * computation. That is {@see NumberingChange}'s arrangement and this is the
 * second thing to want it, which is roughly when a shape stops being a
 * coincidence.
 *
 * ### Three ways it says no, and one of them has a second choice
 *
 *  * **A value the new type cannot read** refuses the whole change, with the
 *    count and the offending values named, which is §5.4's existing refusal
 *    shape. Emptying those rows instead is the customer's explicit second
 *    choice, taken with the report in front of them, never a default and never
 *    something that rides along.
 *  * **A `unique` field whose values collide once converted** is refused before
 *    anything is attempted. The index would have refused it in the middle of the
 *    rewrite and the customer would have got a rollback and a driver error; the
 *    plan groups the converted values and finds the collision first. There is no
 *    second choice for this one, because the rows are perfectly readable and
 *    what has been discovered is that they were always the same value.
 *  * **A derived field** is refused by {@see MetadataEditor::changeType()}, on
 *    the field rather than on the data.
 *
 * ### Whether the door is one-way is said before it closes
 *
 * §7.2 asks for reversibility to be stated per conversion, and the honest way to
 * state it is the way legality is decided: from the data. {@see self::plan()}
 * reads every converted value **back** through the type the field has today, and
 * the conversion is reversible exactly when every row comes back to the value it
 * holds now. Converting the three spellings of one Swiss mobile to `phone` is
 * therefore final, and the page says so: the number is preserved and the
 * spelling is not, and nobody can put back the spaces somebody typed in 2019
 * once they are gone. A conversion that empties a row is never reversible, and
 * the two facts are one flag because a customer reading that page has one
 * question.
 *
 * ### Why this writes history and the numbering backfill does not
 *
 * {@see RecordRepository::setValues()} argues at length that a backfill is one
 * administrative act against a column rather than several hundred separate
 * edits, and must not write a history entry per record. Every word of
 * that is still true and this does the opposite, because the two acts differ in
 * the one way that matters: a backfill writes into rows that held **nothing**,
 * and a conversion restates what somebody **typed**. The history entry is the
 * only place the old spelling continues to exist once the column has been
 * rewritten, and §7.2 makes it the condition on which a lossy conversion is
 * allowed to happen at all. So each touched record gets one entry, under a verb
 * of its own ({@see RecordAction::Converted}), carrying the value the run took
 * away and the value it put there.
 *
 * It is written through the event rather than straight into the history table
 * for the reason §5.2 gives: core does not know what a user is, and an
 * administrative act still has an administrator behind it. The application's
 * listener puts the name on it, exactly as it does for an ordinary save.
 *
 * ### And why it does not go through RecordWriter to do that
 *
 * The obvious shape would be one {@see RecordWriter::save()} per record, which
 * writes history by construction. It cannot work here, and the reason is worth
 * writing down because it is not obvious until it is. The writer computes its
 * diff by running both sides through the field types **as they are now**
 * (§5.2), so that a date submitted as a string and read back as an object are
 * not a change. A type conversion is precisely a change to what "as they are
 * now" means: with the new type in place, the old spelling and the new one are
 * read alike, the diff comes out empty, and the single entry this whole feature
 * exists to write is the one entry that never gets written. So the conversion
 * states its diff instead of deriving one, in the only terms that outlive the
 * change: the text that was in the column, and the text that replaced it.
 *
 * The writer is still used for the part it is right for. When a module derives
 * anything at all (§5.9), every touched record goes through an ordinary save
 * afterwards so that the values that follow from this one are restated, and
 * because the writer says nothing about a save that changed nothing, a record
 * whose totals did not move gets no second entry.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FieldTypeConversion
{
    public function __construct(
        private Connection $connection,
        private FieldTypeRegistry $fieldTypes,
        private RecordRepository $records,
        private RecordWriter $writer,
        private MetadataEditor $editor,
        private UniqueIndex $uniqueIndexes,
        private DerivedValues $derived,
        private EventDispatcherInterface $events,
        // The type's own rules, asked of the converted value. `toStorage()` is
        // the seam §7.2 names, and on its own it is not a verdict: a type that
        // cannot read something is entitled to hand it back untouched rather
        // than to null it, which is exactly what `phone` does so that a mistyped
        // number is refused by name instead of vanishing. So what decides
        // whether a row survives is the same validator every save runs, over the
        // same constraints, against the value as it would be stored.
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * What the change would do, without doing any of it.
     *
     * A read with no side effects, so a screen may call it to explain itself
     * before anybody has clicked anything, and it is exhaustive rather than
     * sampled: it reads every live value in the column. The argument for that
     * cost is on {@see RecordRepository::valueHolders()} and comes to this, a
     * plan built from the first hundred rows is a promise about the other four
     * hundred that nobody checked.
     *
     * @throws \Xivi\Core\Field\UnknownFieldType when either type is not registered
     */
    public function plan(FieldDefinition $field, string $to): ConversionPlan
    {
        $shape = $field->getShape();
        $from = $this->fieldTypes->get($field->getType());
        $target = $this->fieldTypes->get($to);
        $probe = self::probe($field, $to);

        $converts = 0;
        $refuses = 0;
        $changes = 0;
        $reversible = true;
        /** @var array<string, int> $rewritten */
        $rewritten = [];
        /** @var array<string, int> $refusing */
        $refusing = [];
        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, string> $becomes */
        $becomes = [];

        foreach ($this->records->valueHolders($shape, $field) as $holder) {
            $stored = $holder['value'];

            // Both halves of the seam. The type the field has today says what
            // this text means, and the type it is moving to says how that is
            // written down. Neither of them is asked about the other, which is
            // what stops this class growing an opinion about pairs of types.
            $converted = $target->toStorage($from->fromStorage($stored, $field), $probe);
            $text = self::asText($converted);

            if ($text === null || !$this->reads($converted, $target, $probe)) {
                ++$refuses;
                $refusing[$stored] = ($refusing[$stored] ?? 0) + 1;
                // A row that has to be emptied has no way back through anything,
                // so a report offering one would be wrong before it was read.
                $reversible = false;

                continue;
            }

            ++$converts;
            $counts[$text] = ($counts[$text] ?? 0) + 1;

            if ($text === $stored) {
                continue;
            }

            ++$changes;
            $becomes[$stored] ??= $text;
            $rewritten[$stored] = ($rewritten[$stored] ?? 0) + 1;

            // The reverse, expressed as the same sentence read backwards: the
            // new type says what the converted text means, the old one writes it
            // down again, and the door is two-way exactly when that lands on the
            // value the column holds today. Nothing here consults a list of
            // which conversions are known to be lossless, for the same reason
            // nothing above consults a list of which are known to be legal.
            if (self::asText($from->toStorage($target->fromStorage($converted, $probe), $field)) !== $stored) {
                $reversible = false;
            }
        }

        arsort($rewritten);
        arsort($refusing);

        return new ConversionPlan(
            from: $field->getType(),
            to: $to,
            records: $converts + $refuses,
            converts: $converts,
            refuses: $refuses,
            changes: $changes,
            // One more than a page prints, so that "these five" and "at least
            // these five" can be told apart without a second pass, which is the
            // arrangement XIV-109 settled on for shared values.
            rewritten: self::named($becomes, $rewritten),
            refusing: \array_slice($refusing, 0, ConversionPlan::VALUES_NAMED + 1, preserve_keys: true),
            // Only where the promise exists. A collection's field is never
            // unique, because whole-table and within-one-parent are different
            // rules and the engine will not guess (§7.2); asking here would be
            // inventing the answer that §5.4 refuses to invent.
            shared: $field->isUnique() && $shape instanceof ModuleDefinition
                ? array_filter($counts, static fn (int $held): bool => $held > 1)
                : [],
            reversible: $reversible,
        );
    }

    /**
     * And doing it, once somebody has said the word.
     *
     * Everything is one transaction, so a refusal, a browser closing or a column
     * too large to finish leaves the field exactly as it was, with every value
     * spelled the way it was spelled this morning.
     *
     * The order inside it is the whole design and each step is there because of
     * the one before:
     *
     *  1. **the plan again**, computed here rather than repeated back from the
     *     page, so that a record saved while somebody was reading it is counted;
     *  2. **the refusals**, before a single row is written;
     *  3. **the `unique` index comes down**, because a row converted early can
     *     collide with a row not converted yet even when the state they all end
     *     up in is perfectly unique, and an index cannot tell a passing collision
     *     from a real one;
     *  4. **the values, one row at a time**, each with the history entry that
     *     says what it used to be;
     *  5. **the definition**, which is also where the index goes back up over
     *     values already proved not to collide;
     *  6. **the derivers**, over the touched records only, and only on a module
     *     that has any.
     *
     * @param bool $emptyRefused the customer's explicit second choice, taken with
     *                           the report in front of them: empty the rows the new
     *                           type cannot read, rather than leave the field alone.
     *                           Never a default, and there is no way to reach this
     *                           with it true except by asking for it
     *
     * @return ConversionPlan what was actually done, which is the plan as it was
     *                        inside the transaction rather than the one on the page
     *
     * @throws MetadataChangeRefused
     */
    public function convert(FieldDefinition $field, string $to, bool $emptyRefused = false): ConversionPlan
    {
        return $this->connection->transactional(
            fn (): ConversionPlan => $this->run($field, $to, $emptyRefused),
        );
    }

    /**
     * The conversion itself, with nothing between it and the transaction.
     *
     * Split out only so that {@see self::convert()} can hold the transaction
     * without indenting the six steps its docblock is made of.
     *
     * @throws MetadataChangeRefused
     */
    private function run(FieldDefinition $field, string $to, bool $emptyRefused): ConversionPlan
    {
        $shape = $field->getShape();
        $module = $shape instanceof CollectionDefinition ? $shape->getParent() : $shape;
        \assert($module instanceof ModuleDefinition);

        // The two refusals that are about the field rather than about anything
        // stored in it, asked before the column is read at all.
        //
        // {@see MetadataEditor::changeType()} asks them again and keeps them,
        // because it is the write path and an import or a console command meets
        // it there rather than here. This copy is not that rule being written
        // twice, it is when it is asked: reaching the same refusal at the *end*
        // would mean a table scan, an index dropped and every row rewritten,
        // all rolled back, for an answer that was true before any of it started.
        if ($field->isDerived()) {
            throw MetadataChangeRefused::typeOfADerivedField($field->getKey());
        }

        if ($to === $field->getType()) {
            throw MetadataChangeRefused::typeUnchanged($field->getKey(), $to);
        }

        $plan = $this->plan($field, $to);

        if ($plan->blocked()) {
            throw MetadataChangeRefused::conversionWouldShareValues(
                $field->getKey(),
                $to,
                $plan->shared,
                ConversionPlan::VALUES_NAMED,
            );
        }

        if ($plan->refused() && !$emptyRefused) {
            throw MetadataChangeRefused::valuesCannotBeRead(
                $field->getKey(),
                $to,
                $plan->refuses,
                $plan->refusing,
                ConversionPlan::VALUES_NAMED,
            );
        }

        if ($plan->refused() && $field->isRequired()) {
            throw MetadataChangeRefused::cannotEmptyARequiredField($field->getKey(), $plan->refuses);
        }

        $from = $this->fieldTypes->get($field->getType());
        $target = $this->fieldTypes->get($to);
        $probe = self::probe($field, $to);

        $this->uniqueIndexes->drop($shape, $field);

        $now = new \DateTimeImmutable();
        /** @var array<int, list<array{child: int|null, from: string, to: string|null}>> $entries */
        $entries = [];

        foreach ($this->records->valueHolders($shape, $field) as $holder) {
            $stored = $holder['value'];
            $converted = $target->toStorage($from->fromStorage($stored, $field), $probe);
            $text = self::asText($converted);
            $survives = $text !== null && $this->reads($converted, $target, $probe);

            if ($survives && $text === $stored) {
                // Nothing to write and nothing to record. A conversion that
                // leaves a value exactly as it was is not something that
                // happened to that record, and an entry saying so would be the
                // "edited, nothing changed" noise §5.2 keeps out of a timeline.
                continue;
            }

            $this->records->writeStoredValue($shape, $field, $holder['id'], $survives ? $converted : null);

            // Keyed by the record whose timeline this belongs on, which for a
            // collection's field is the parent (§5.2): a contact's addresses are
            // part of what the contact is, and their events go in its table.
            $entries[$holder['parent'] ?? $holder['id']][] = [
                'child' => $holder['parent'] === null ? null : $holder['id'],
                'from' => $stored,
                'to' => $survives ? $text : null,
            ];
        }

        foreach ($entries as $recordId => $changes) {
            $record = $this->records->find($module, $recordId);

            if ($record === null) {
                continue;
            }

            $this->events->dispatch(new RecordChanged(
                $module,
                // The record as it now is rather than a stub carrying an id.
                // Other subscribers read it (§6), and one that was handed an
                // empty payload would conclude that this record had stopped
                // carrying everything on it.
                $record,
                RecordAction::Converted,
                self::changesFor($field, $shape, $changes),
                $now,
            ));
        }

        $this->editor->changeType($field, $to);

        // §5.9's half, and only where there is one. A field that other values
        // follow from has just been restated, so the values that follow from it
        // are worked out again from what is there now. The writer says nothing
        // about a save that changed nothing, so a record whose totals did not
        // move gets no entry out of this, and a module with no deriver at all
        // does not reach it.
        if ($this->derived->derivesOn($module)) {
            foreach (array_keys($entries) as $recordId) {
                $this->rederive($module, $recordId);
            }
        }

        return $plan;
    }

    /**
     * Run a touched record through an ordinary save, so that whatever follows
     * from the converted value is worked out again (§5.9).
     *
     * The collections are handed over in full because that is what a save means:
     * a collection missing from the call is one whose rows are being deleted. It
     * is the rows as they stand a moment after the conversion wrote them, so the
     * only thing that can differ on the way out is what a deriver decided.
     */
    private function rederive(ModuleDefinition $module, int $recordId): void
    {
        $record = $this->records->find($module, $recordId);

        if ($record === null) {
            return;
        }

        $children = [];

        foreach ($module->getCollections() as $collection) {
            $children[$collection->getKey()] = array_map(
                static fn (Record $row): array => ['id' => (int) $row->id, 'data' => $row->data],
                $this->records->findChildren($collection, $recordId),
            );
        }

        $this->writer->save($module, $record, $children, RecordAction::Converted);
    }

    /**
     * The history entry for one record, in the shape §5.2 stores it.
     *
     * Two branches, because a field on a module and a field on one of its
     * collections are the same change told about different things: the first is
     * a value on the record, the second is a value on a row of it, and the entry
     * has to say which row. The row shape is the writer's own
     * (`action`, `child_id`, `changes`), copied rather than invented so that the
     * template that draws a timeline needs no idea this feature exists.
     *
     * The label is the field's as it is now, which is when this happened, and it
     * is stored rather than looked up later for §5.2's reason: renaming a field
     * next year must not rewrite what the timeline says.
     *
     * @param list<array{child: int|null, from: string, to: string|null}> $changes
     */
    private static function changesFor(
        FieldDefinition $field,
        ShapeDefinition $shape,
        array $changes,
    ): RecordChanges {
        $key = $field->getKey();

        if (!$shape instanceof CollectionDefinition) {
            return new RecordChanges(fields: [
                $key => ['label' => $field->getLabel(), 'from' => $changes[0]['from'], 'to' => $changes[0]['to']],
            ]);
        }

        return new RecordChanges(collections: [
            $shape->getKey() => array_map(static fn (array $change): array => [
                'action' => 'updated',
                'child_id' => $change['child'],
                'changes' => [
                    $key => ['label' => $field->getLabel(), 'from' => $change['from'], 'to' => $change['to']],
                ],
            ], $changes),
        ]);
    }

    /**
     * The field as it would be, for the one type that has to answer questions
     * about it.
     *
     * A type is handed the definition on every call it makes, because how a
     * value is read depends on how the field is configured: which country a
     * phone number is dialled in, how long a text may be. So the type moving in
     * has to be asked about a field of *its* type, not about the one that is
     * there. A detached clone is the whole of it; nothing persists it, and
     * nothing but this class ever sees it.
     *
     * It carries no options at all, which matches what
     * {@see MetadataEditor::changeType()} will write: the old type's settings
     * are answers to questions the new type does not ask, and the new type's are
     * edited afterwards on the field's own form. A dry run that assumed
     * otherwise would be reporting on a field nobody is about to have.
     */
    private static function probe(FieldDefinition $field, string $to): FieldDefinition
    {
        $probe = clone $field;
        $probe->setType($to);
        $probe->setOptions([]);

        return $probe;
    }

    /**
     * Whether the type moving in accepts what it just read.
     *
     * `toStorage()` normalises and does not judge, on purpose: `phone` hands an
     * unreadable string back untouched precisely so that the validator can
     * refuse it by name rather than have it disappear into a null. So the
     * verdict is the same one every save reaches, over the same constraints,
     * against the value in the form it would be stored in, which is where
     * {@see \Xivi\Core\Validation\RecordValidator} also puts it.
     *
     * Required-ness is not among them and must not be: whether a field may be
     * empty is a property of the field and is being left exactly as it was.
     */
    private function reads(mixed $converted, FieldType $target, FieldDefinition $probe): bool
    {
        return \count($this->validator->validate($converted, $target->constraints($probe))) === 0;
    }

    /**
     * A stored value as `data ->> 'key'` would hand it back.
     *
     * Everything this class compares is compared as that text: it is what the
     * `unique` index is built over (§7.2), what a refusal prints, and what came
     * out of the column in the first place. Doing it any other way would mean
     * two records "sharing" a value the index would happily accept, or a message
     * naming something nobody can search for.
     */
    private static function asText(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * The most-held few of what becomes what, with the map and the counts lined
     * up.
     *
     * The counts decide the order, because the value four hundred records hold
     * is the one worth showing; the map is what the page prints. Ordered rather
     * than arbitrary so that two people looking at the same plan see the same
     * five examples.
     *
     * @param array<string, string> $becomes value => what it would become
     * @param array<string, int>    $counts  value => how many records hold it, worst first
     *
     * @return array<string, string>
     */
    private static function named(array $becomes, array $counts): array
    {
        $named = [];

        foreach (\array_slice(array_keys($counts), 0, ConversionPlan::VALUES_NAMED + 1) as $value) {
            $named[$value] = $becomes[$value];
        }

        return $named;
    }
}

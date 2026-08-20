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

namespace Xivi\Core\Field\Type;

use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\Autocompletes;
use Xivi\Core\Field\HoldsSeveralValues;
use Xivi\Core\Field\PointsAtAModule;
use Xivi\Core\Field\PrimesFromRecords;
use Xivi\Core\Form\RecordReferenceType;
use Xivi\Core\Query\Operator;
use Xivi\Core\Record\ReferenceTargets;

/**
 * Links to several records at once, stored as an array of their ids (XIV-113).
 *
 * The tags on a contact, the people on a project, the categories an article is
 * in. A {@see ReferenceFieldType} holds exactly one record and plenty of real
 * relationships are not like that.
 *
 * ## Why this is a type and not a `multiple` option on `reference`
 *
 * §5.21's rule, and XIV-127 settled it before this ticket was opened: an option
 * is the wrong shape when ticking it *reinterprets stored data*. One id becoming
 * a list of ids changes the storage shape of every record already holding one,
 * which is retroactivity at its strongest. Every reader, every filter, every
 * export and every marker would have to accept both spellings for ever, and the
 * day somebody unticked the box there would be no answer to which of four links
 * survives. So `reference` is untouched by this file: it still stores a bare
 * integer, and nothing here ever writes one or reads one.
 *
 * The accepted cost is the same one §5.21 accepted for `markdown`: there is no
 * path from a populated `reference` to this type that is not a §7.2 conversion,
 * and that conversion is offered: {@see self::toStorage()} reads a single id as
 * a list of one, which is what makes the dry run over the whole column survive.
 * The way back is not, because four links cannot become one.
 *
 * ## Storage: an array in the JSONB document, said out loud
 *
 * `{"tags": [3, 12, 41]}`: a JSON array of record ids, never a joined string.
 * A comma-joined string would make every question below worse: the containment
 * filter would become a `LIKE` that matches 13 when asked about 3, the export
 * would have to escape a character the ids cannot contain, and priming would
 * have to parse text to find out what to fetch. The reason to avoid it is not
 * that an id might contain a comma; it is that a database that can index and
 * compare an array is being handed a string instead.
 *
 * ## Order is not meaningful, and the order on save is the proof
 *
 * **A set.** The values are de-duplicated and sorted ascending by id on the way
 * in, so two saves that picked the same records produce the same stored array,
 * and {@see \Xivi\Core\Record\RecordWriter}'s diff cannot report a reordering
 * as a change worth a history entry: it compares storage forms with `===`, and
 * an array with `===` is order-sensitive.
 *
 * Tags are a set and the people on a project *might* be a list somebody arranges,
 * and this picks the simpler of the two deliberately: an arrangement nobody can
 * see is an arrangement nobody maintains, and a widget that lets somebody drag
 * four names into an order is a feature of its own rather than a detail of this
 * one. Should it ever be wanted it is a third type and not an option here, for
 * the reason at the top of this docblock: whether the order means anything is a
 * question about what is stored.
 *
 * The display order follows the stored order rather than the names, because
 * sorting names is a collation decision this engine has not taken anywhere else
 * (§8.4.2 is about formatting figures, not about where `ä` lands) and taking it
 * here would be taking it for the whole application by accident.
 *
 * ## `unique` is refused
 *
 * And refused deliberately rather than left to match nothing. XIV-109 enforces
 * the flag with a partial index over `data ->> 'key'`; for a JSON array that
 * expression is the array's own *text*, so the index would build without
 * complaint and quietly mean "no two records hold exactly the same set". That is
 * not the question somebody ticking the box is asking; they mean "no two
 * records share any of these". It is not what the validator in front of the
 * index checks either, and a rule that is enforced by an index and not by the message
 * beside it is the disagreement XIV-109 exists to end. The refusal lives in
 * {@see \Xivi\Core\Metadata\MetadataEditor}, keyed on {@see HoldsSeveralValues}
 * rather than on this type's name, and the editor does not draw the checkbox.
 *
 * ## Filtering: containment, and nothing that pretends to be an `OR`
 *
 * {@see Operator::Includes} and {@see Operator::Excludes}, *has this record*
 * and *does not have this record*, compiled as a `@>` containment test against
 * the stored array. That is one comparison of one value, which is all §5.3's
 * compiler emits.
 *
 * **"Has any of these" is not offered**, and that is the whole point of naming
 * the two that are. It needs the `OR` tree §7.3 says the query layer still lacks;
 * two `includes` filters in one URL mean *and*, like every other pair of filters,
 * which is a different question and the honest one to answer today. A hop
 * *through* this field, `tags.name`, is not offered either: through a single
 * reference a hop is "the record it names", through a set it would be "any of
 * the records it names", and a path that reads the same and means something else
 * is worse than a path that is not there.
 *
 * Sorting is refused by the compiler (§5.3), on the argument §5.3 already makes
 * about collections: four links are four values and none of them is the record's.
 *
 * ## What a document and an email print
 *
 * The names, separated by {@see self::SHOWN}: `Urgent, Follow up, Zürich`. §5.13.1
 * decided that a *collection* in an email is a table, and this deliberately
 * differs from it for the reason that section gives for its own shape: a
 * collection's rows have columns, so a table is the smallest thing that can show
 * them, and a table's grammar (`[lines:kind.col,col]`) exists to say which. A set
 * of names has one column, and a one-column table is a list somebody has to style
 * for no gain. So this needs no new marker grammar at all: `[contact.tags]` is an
 * ordinary record marker filled through `display()`, in a .docx and in an email
 * alike, and the escaping property §5.13.1 protects is untouched because what
 * comes out is text.
 *
 * ## Not in
 *
 * **A relationship with fields of its own**, *this person is the lead on this
 * project*, is a collection and not a multi-value reference. §5.1 covers it, and
 * the moment the link needs to carry a value the array cannot hold one. Nothing
 * here should grow towards it.
 *
 * **An anchor per name.** {@see \Xivi\Core\Field\LinksToRecord} answers with one
 * link and a template draws one anchor; four would be a second Twig function and
 * a change to every place a value is drawn, for a door that already exists on the
 * other side: XIV-52's reverse-link card on the target's own page lists what
 * points at it, and this type is visible to it (§7.6).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MultiReferenceFieldType implements Autocompletes, HoldsSeveralValues, PointsAtAModule, PrimesFromRecords
{
    /** How many links a generated field gets, at most. Enough to look like tags. */
    private const int SAMPLED = 3;

    /** What separates the names when a value is *shown*, as opposed to stored. */
    private const string SHOWN = ', ';

    /**
     * The single reference, and every question this type does not answer for
     * itself.
     *
     * **Delegation rather than a copy**, and the reason is the memo behind it.
     * Naming a record means resolving its title fields through
     * {@see ReferenceTargets}, and picking a plausible one for demo data means a
     * filtered page of the target module; both already exist on the single type,
     * both are memoised per request there, and a second implementation would be a
     * second set of queries per field for an answer that has to be identical
     * anyway. The two types read the same `module` option, which is what
     * {@see PointsAtAModule} means, so `display()` and `sample()` can be asked
     * value by value with this field's own definition and give the answer the
     * single reference would.
     *
     * It also settles the stale cases without restating them: a link into a
     * module the customer no longer has, and a link at a deleted record, read as
     * `#id` here because they read as `#id` there (§7.6).
     */
    public function __construct(
        private readonly ReferenceFieldType $single,
        private readonly ReferenceTargets $targets,
    ) {
    }

    public function key(): string
    {
        return 'multi_reference';
    }

    public function label(): string
    {
        return 'Links to several records';
    }

    /**
     * Every item is a record id, and nothing says the record exists.
     *
     * The second half is {@see ReferenceFieldType}'s decision inherited rather
     * than re-taken: whether an id points at something is a question about
     * another table, and answering it on every save would validate what a foreign
     * key should answer once (§7.6, still open). What *is* checked is that an
     * item is an id at all, and it is checked here rather than swallowed in
     * {@see self::toStorage()}. See there for why that matters to an import.
     *
     * `Positive` as well as `Type`, because a negative integer is an integer: it
     * would satisfy the first constraint, address no row, and be the one bad
     * value this pair would otherwise let through.
     */
    public function constraints(FieldDefinition $field): array
    {
        return [
            new Assert\All([
                new Assert\Type(
                    type: 'int',
                    message: '{{ value }} is not a record id. Several records are named by their ids '
                        . 'separated by a comma, as in 12,34.',
                ),
                new Assert\Positive(),
            ]),
        ];
    }

    /**
     * Whatever was submitted, imported or handed over, as the stored array.
     *
     * Four shapes arrive here and each has a reason. An **array** comes from the
     * picker, from a module assembling a record and from a value read back out of
     * storage. A **string** comes from a spreadsheet cell and from a filter box,
     * and is split on {@see HoldsSeveralValues::SEPARATOR}. A bare **integer**
     * comes from a cell holding one id, which a spreadsheet hands over as a
     * number, and from a `reference` being converted into this type (§7.2): the
     * dry run reads every stored id through this method, so a single link becomes
     * a list of one and the whole column survives. **Null and empty** are a field
     * nobody filled in, and are stored as nothing at all rather than as `[]`, so
     * that "absent" stays the single representation of empty the way
     * {@see \Xivi\Core\Record\UniqueIndex} and every `is empty` filter already
     * assume.
     *
     * **An item that is not a record id is kept rather than dropped**, and that is
     * the load-bearing line of this method. Dropping it would turn a spreadsheet
     * cell reading `12,tuesday,34` into a record quietly holding two links, and
     * §5.6's whole promise is that nothing goes in silently. Keeping it means the
     * value fails this type's own constraints with the item named, which reaches
     * the person importing as a line naming the sheet, the row and the field,
     * and reaches nobody at all through the form, where the picker's choice list
     * refused the value long before this. It is {@see IntegerFieldType}'s answer
     * to `"12abc"` and {@see PeriodFieldType}'s to a date it cannot read: inventing
     * a plausible value nobody typed is worse than being refused.
     *
     * @return list<mixed>|null
     */
    public function toStorage(mixed $value, FieldDefinition $field): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $items = \is_array($value) ? array_values($value) : self::split($value);

        $ids = [];
        $refused = [];

        foreach ($items as $item) {
            if ($item === null || $item === '') {
                // A blank cell in a list of them: `12,,34` is two links and a
                // stray separator, not a third value nobody can read.
                continue;
            }

            $id = self::idOf($item);

            if ($id === null) {
                $refused[] = $item;

                continue;
            }

            // Keyed by the id itself, which is the de-duplication: picking the
            // same record twice is one link, not two.
            $ids[$id] = $id;
        }

        // Ascending, which is what makes this a set rather than a sequence. See
        // the class docblock on why the diff cares.
        ksort($ids);

        $stored = [...array_values($ids), ...$refused];

        return $stored === [] ? null : $stored;
    }

    /**
     * The stored array, as the ids the application works with.
     *
     * Anything that is not a list is a value under a key this field inherited
     * from an earlier definition, since §5.4 keeps a removed field's values and a
     * new field may meet whatever the old one left. It reads as nothing rather
     * than as an error on a page that is only trying to draw an input.
     *
     * @return list<int>
     */
    public function fromStorage(mixed $value, FieldDefinition $field): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = self::idOf($item);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The same picker, told it may take more than one (XIV-35, XIV-36).
     *
     * **{@see RecordReferenceType} rather than a second form type**, because
     * everything that makes it worth having is arity-independent: the candidates
     * are the target module's records scoped to what this reader may see, the
     * dropdown is capped at {@see RecordReferenceType::MAX_CHOICES} and says so
     * when it truncates, and past {@see \Xivi\Core\Field\Autocomplete::AUTO_ABOVE}
     * candidates the cap is replaced by a search box rather than raised. All of
     * that applies here unchanged, which is the answer to "does XIV-35's cap
     * apply too": it is not applied again, it is the same code.
     *
     * **The widget is `symfony/ux-autocomplete`'s, confirmed rather than assumed.**
     * Its Stimulus controller reads `select.multiple` and configures Tom Select
     * from it: the `remove_button` plugin instead of `clear_button`, and the
     * selected options restored as items on a re-render. So a multi-select is
     * something the installed package does rather than something this would have
     * to build. Under `never` it is a plain `<select multiple>` and no JavaScript
     * at all.
     */
    public function formType(): string
    {
        return RecordReferenceType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return [
            ...$this->single->formOptions($field),
            'multiple' => true,
            // ChoiceType ignores a placeholder when it may take several, and
            // leaving one set would be a line of configuration that reads as if
            // it did something.
            'placeholder' => null,
        ];
    }

    /**
     * The names, separated by commas.
     *
     * One `display()` per id, through the single reference, so every rule about
     * what a link *reads* as is stated once: the record's own title fields
     * (§5.4), `#id` for a stale link or a module the customer no longer has
     * (§7.6), and the name read unscoped because whoever may read this record may
     * read what it is for (§8.4).
     *
     * Plain text, like every other `display()`, and the three callers named in
     * {@see \Xivi\Core\Field\LinksToRecord} are why: a document fills a .docx with
     * it, an export writes it into a cell, and a record's own name is built out of
     * it.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        $names = [];

        foreach ($this->fromStorage($value, $field) as $id) {
            $shown = $this->single->display($id, $field);

            if ($shown !== '') {
                $names[] = $shown;
            }
        }

        return implode(self::SHOWN, $names);
    }

    /**
     * Has this record, has not, empty, filled, and no fifth.
     *
     * `Equals` is deliberately absent. Equality against a set would have to mean
     * either "is exactly this set", which no filter box can express with one id,
     * or "contains it", which is what `Includes` says without the ambiguity. Two
     * spellings of one question are how a filter comes to mean different things
     * on different pages.
     */
    public function operators(): array
    {
        return [Operator::Includes, Operator::Excludes, Operator::IsEmpty, Operator::IsNotEmpty];
    }

    /**
     * The stored array, as something Postgres can ask a containment question of.
     *
     * `data->>'key'` hands over the array's *text*, so it has to be read back as
     * `jsonb`, and the guard in front of the cast is not decoration. §5.4 keeps a
     * removed field's values, and a field added later with the same key meets
     * them: a `text` field that held `hello` and was removed leaves a column this
     * expression would try to parse as JSON, which is an error on a list page
     * rather than a row that does not match.
     *
     * The pattern is this type's own storage alphabet (a bracket, digits,
     * separators, a bracket), so anything it admits is valid JSON by construction,
     * and everything else is read as the empty array and matches nothing. That is
     * the honest answer for a value this field never wrote.
     */
    public function comparableSql(string $accessor): string
    {
        return sprintf(
            "CASE WHEN %1\$s ~ '^\\[[0-9, ]*\\]$' THEN (%1\$s)::jsonb ELSE '[]'::jsonb END",
            $accessor,
        );
    }

    /**
     * A handful of real records, for demo data (§5.17).
     *
     * Asked of the single reference once per link, so the candidates are read
     * once per field and shared with it rather than fetched again. Duplicates
     * collapse in {@see self::toStorage()}, so a field pointing at a module with
     * three records in it produces a short list rather than the same name three
     * times.
     *
     * Null for an empty one, and only when the field allows it: a required field
     * asks {@see self::SAMPLED} times and the single reference does not refuse a
     * required field, so at least one comes back.
     *
     * @return list<int>|null
     */
    public function sample(FieldDefinition $field, int $sequence): ?array
    {
        $ids = [];

        for ($n = 0; $n < self::SAMPLED; ++$n) {
            $id = $this->single->sample($field, $sequence);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        ksort($ids);

        return $ids === [] ? null : array_values($ids);
    }

    /**
     * The one question a reference is not a reference without, answered exactly
     * as the single one answers it (XIV-144).
     *
     * The **same option name**, `module`, and that is a promise rather than a
     * coincidence: {@see \Xivi\Core\Metadata\MetadataEditor} refuses to repoint a
     * populated field, {@see \App\Controller\FieldController} draws the control,
     * and XIV-52's reverse-link card looks for fields whose target is this
     * module. All three read that key, and a second type keeping it somewhere
     * else would be invisible to every one of them. It is the shape of trap
     * {@see \App\Tests\Functional\Engine\ValueListReachesEveryTypeTest} was
     * written about for `list`, one capability over.
     *
     * **{@see \Xivi\Core\Field\PointsAtAList} is deliberately not declared.** The
     * values here are records, not entries on a list the customer keeps; §5.26's
     * settlement is that a multi-value field pointing at a `value_list` would use
     * that option and that capability, and this one does not point at one.
     *
     * @return list<non-empty-list<string>>
     */
    public function needs(): array
    {
        return [[ReferenceFieldType::MODULE]];
    }

    /**
     * The comparison that finds the records whose value here names a given one
     * (XIV-52).
     *
     * Containment, where a single reference answers equality. The reverse-link
     * card asks the type rather than switching on its name, which is what makes
     * that count see inside arrays without knowing what an array is.
     */
    public function findsTargetBy(): Operator
    {
        return Operator::Includes;
    }

    /**
     * Every record this page will name, in one query per target module (XIV-54).
     *
     * The same shape as {@see ReferenceFieldType::primeFrom()} and it has to be:
     * a page of 25 records with four links each is a hundred names, which is a
     * hundred lookups without this and one `WHERE id IN (…)` with it. The
     * multiplier this type adds is exactly why the guarantee is worth restating
     * here rather than inheriting by accident.
     *
     * Ids collapse per *target module* rather than per field, so two fields
     * pointing at contacts are one query, and a page whose records all carry the
     * same tag asks about it once.
     */
    public function primeFrom(array $fields, array $records): void
    {
        /** @var array<string, array<int, int>> $ids */
        $ids = [];

        foreach ($fields as $field) {
            $module = ReferenceFieldType::targetModule($field);

            if ($module === '') {
                // A field half-configured in the editor: it points nowhere, so
                // there is nothing to fetch and nothing to render either.
                continue;
            }

            foreach ($records as $record) {
                foreach ($this->fromStorage($record->get($field->getKey()), $field) as $id) {
                    $ids[$module][$id] = $id;
                }
            }
        }

        foreach ($ids as $module => $found) {
            $this->targets->prime($module, array_values($found));
        }
    }

    /**
     * Wider than a single link, because it holds several of them and each is a
     * record's name (§5.4). Half a row is what a handful of tags needs before it
     * starts wrapping, and a whole row is what a paragraph gets.
     */
    public function defaultWidth(): int
    {
        return 6;
    }

    /**
     * One cell, or one filter box, as its items.
     *
     * Whitespace around a separator is somebody typing `12, 34`, which is the
     * same value and not a mistake worth refusing.
     *
     * @return list<mixed>
     */
    private static function split(mixed $value): array
    {
        if (!\is_scalar($value)) {
            // Not a list and not text: an object, a boolean, something no caller
            // should be handing over. Kept whole so the constraints name it,
            // rather than being coerced into a string that would look imported.
            return [$value];
        }

        return array_map(trim(...), explode(HoldsSeveralValues::SEPARATOR, (string) $value));
    }

    /**
     * One item as a record id, or null when it is not one.
     *
     * Deliberately strict about what an id looks like: digits, and a value above
     * zero. A spreadsheet hands back `7.0` where somebody typed `7`, which is why
     * a whole float counts; `7.5` does not, because a row and a half is not a
     * record and reading it as row 7 would be inventing the value this whole
     * method exists to refuse.
     */
    private static function idOf(mixed $item): ?int
    {
        if (\is_int($item)) {
            return $item > 0 ? $item : null;
        }

        if (\is_float($item)) {
            return $item > 0 && floor($item) === $item && $item < \PHP_INT_MAX ? (int) $item : null;
        }

        if (\is_string($item) && preg_match('/^[0-9]+$/', trim($item)) === 1) {
            $id = (int) trim($item);

            return $id > 0 ? $id : null;
        }

        return null;
    }
}

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

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Autocompletes;
use Xivi\Core\Field\Enumerates;
use Xivi\Core\Field\HoldsSeveralValues;
use Xivi\Core\Field\PointsAtAList;
use Xivi\Core\Field\ShowsSeveralBadges;
use Xivi\Core\Field\ValueBadge;
use Xivi\Core\Query\Operator;

/**
 * Several values out of a closed set the customer defines ([XIV-169]).
 *
 * The languages somebody speaks, the certifications a supplier holds, the days a
 * room is bookable, the channels a customer agreed to be contacted on. A
 * {@see ChoiceFieldType} holds exactly one option and every one of those is
 * several, so until this existed they were a comma-separated text field with
 * nothing validating it and no filter that could find one.
 *
 * It is the fourth square of a grid whose other three were already built: one
 * record ({@see ReferenceFieldType}), several records
 * ({@see MultiReferenceFieldType}), one option ({@see ChoiceFieldType}), several
 * options. Almost everything below is one of those two neighbours' decision
 * inherited rather than a new one, and the docblock says which is which, because
 * the interesting part of this type is the two places it deliberately differs.
 *
 * ## What is XIV-113's, unchanged
 *
 * **A type, not a `multiple` option on `choice`** (§5.21, §5.29). Ticking a box
 * that turns one value into a list reinterprets every record already holding
 * one, which is retroactivity at its strongest: every reader, every filter,
 * every export and every marker would have to accept both spellings for ever,
 * and unticking it would have no answer to which of four values survives. So
 * `choice` is untouched by this file. It still stores a bare string, and nothing
 * here ever writes one or reads one back as one.
 *
 * **Stored as a JSON array**, `{"languages": ["de", "fr"]}`, said out loud
 * because it is load-bearing rather than incidental. A joined string would make
 * the containment filter below a `LIKE`, and `LIKE '%de%'` matches a record
 * holding `de_ch` and one holding `swede`. Every option key here is derived
 * through {@see ChoiceFieldType::valueFor()}, so keys really do sit inside one
 * another: `en` and `en_gb` are two perfectly ordinary options of one field.
 * Postgres' `@>` over an array asks about elements and cannot make that mistake.
 *
 * **`unique` is refused**, on XIV-109's reason as {@see HoldsSeveralValues}
 * states it: the partial index is over `data ->> 'key'`, which for an array is
 * the array's own text, so it would build perfectly and quietly mean "no two
 * records hold the same whole set". Refused in the editor and in the installer,
 * keyed on the capability rather than on this type's name, and the checkbox is
 * not drawn.
 *
 * **Sorting is refused**, on §5.3's argument: every ordering ends on the record
 * id so a LIMIT has a total order, and a record with three languages has three
 * values and none of them is the record's. The list header asks the same
 * capability before it offers the link, so nobody meets the refusal by clicking.
 *
 * **Filtering is containment, one value at a time**, {@see Operator::Includes}
 * and {@see Operator::Excludes}. "Has any of these" stays unoffered because it
 * is the `OR` tree §7.3 says the query layer has not got; two `includes` in one
 * URL mean *and*, like every other pair of filters.
 *
 * **Export and import go through {@see HoldsSeveralValues::SEPARATOR}**, and
 * here the comma is safe for a reason worth one line rather than by luck.
 * `ChoiceFieldType::valueFor()` runs every key through
 * `preg_replace('/[^a-z0-9_]+/', ...)`, so a key is lowercase ASCII, digits and
 * underscores whatever the customer typed as the label. A comma cannot occur in
 * one, so there is nothing for an escape to resolve.
 *
 * ## The two decisions that are this type's own
 *
 * ### Canonical order is the field's option order, not ascending
 *
 * §5.29 de-duplicates and sorts ids **ascending**, and says why: ids mean
 * nothing, so any total order will do, and ordering *names* is a collation
 * decision this engine has taken nowhere else. Options are the other case. The
 * field already carries an order, the one the customer arranged in the editor,
 * and it is the order they see in the dropdown they picked from. Sorting keys
 * alphabetically would put `urgent` after `low` and read as a bug on a page
 * where the picker above it reads the other way round.
 *
 * So this stores **de-duplicated and canonicalised into the field's option
 * order** ({@see self::toStorage()}), which keeps the requirement §5.29's rule
 * actually exists for: two saves that picked the same options produce the same
 * stored array, so {@see \Xivi\Core\Record\RecordWriter}'s diff, which compares
 * storage forms with `===` and is therefore order-sensitive, cannot report a
 * reordering as a change worth a history entry.
 *
 * And it **displays from the field's current options** rather than from the
 * stored order ({@see self::display()}, {@see self::badgesOf()}). That second
 * half is the one that pays: a customer rearranging their options in the editor
 * changes what every record reads like and rewrites not one row. The storage
 * order and the display order agree on every record saved since the last
 * rearrangement and disagree on the older ones, which costs nothing, because
 * nothing but the diff ever reads the stored order and the diff is comparing two
 * arrays rather than reading either.
 *
 * ### The widget is a plain multiple select, not the autocomplete endpoint
 *
 * {@see MultiReferenceFieldType} reaches for {@see \Xivi\Core\Form\RecordReferenceType}
 * and `symfony/ux-autocomplete`'s search endpoint because a module can hold nine
 * thousand records, and no dropdown is honest about that. This type has the
 * opposite problem to solve, which is to say it has none: the options are a
 * closed list in the field's own settings or a list the customer keeps, they are
 * in the page already, and the browser can filter what is in the page. Reaching
 * for the endpoint here would be borrowing a solution to a problem this type
 * does not have, and buying a permission question, a ceiling and a round trip
 * for it.
 *
 * So the form type is `ChoiceType` with `multiple`, and the `autocomplete`
 * option {@see Autocompletes} already offers on `choice` decides whether Tom
 * Select narrows it client-side, exactly as it does for one value. That is
 * XIV-36's *cheaper* half, the one that has no endpoint behind it at all.
 *
 * `expanded` is false at every size, which is a decision rather than a default:
 * checkboxes read beautifully for the four channels a customer may be contacted
 * on and are a page and a half for the four hundred regions a shared list can
 * hold, and one control that works at both sizes beats a second option deciding
 * between them.
 *
 * ## Not in
 *
 * **An arranged list.** The values are a set, so *which* options a record holds
 * is the whole of what is stored and the order somebody picked them in is not
 * kept. An order somebody arranges is a third type on §5.29's rule, never an
 * option here, because whether the order means anything is a question about what
 * is stored.
 *
 * **A hop through it**, on §5.29's reason: through one value a hop reads as "the
 * thing it names" and through a set it would read the same and mean "any of
 * them".
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MultiChoiceFieldType implements Autocompletes, HoldsSeveralValues, PointsAtAList, ShowsSeveralBadges
{
    /** What separates the labels when a value is *shown* as one line, as opposed to stored. */
    private const string SHOWN = ', ';

    /** How many options a generated field picks, at most. Enough to look like a set. */
    private const int SAMPLED = 3;

    /**
     * The single choice, and every question about *one* option that this type
     * does not answer differently.
     *
     * **Delegation rather than a copy**, which is {@see MultiReferenceFieldType}'s
     * arrangement with {@see ReferenceFieldType} and it is right here for a
     * sharper reason. The two types read the same two options, `choices` and
     * `list`, and they have to: {@see \Xivi\Core\ValueList\ValueListUsage} finds
     * every field pointing at a shared list by reading exactly that key, and a
     * second type keeping its list somewhere else would be invisible to the
     * counts, to the refusal that stops an entry being removed from under records
     * holding it, and to the merge. §5.26 promised that a multi-value field would
     * point at the same rows through the same option, and reading them through
     * the type that defined them is how that promise stops being a convention.
     *
     * So which entries there are, which of them a picker indents, and which list
     * a field names are all asked of {@see ChoiceFieldType}, and this file owns
     * only what changes when there are several of them.
     */
    public function __construct(private readonly ChoiceFieldType $single)
    {
    }

    public function key(): string
    {
        return 'multi_choice';
    }

    public function label(): string
    {
        return 'Several choices';
    }

    /**
     * Every item is one of this field's options, and nothing else is.
     *
     * {@see Assert\All} around the pair {@see ChoiceFieldType} applies to its one
     * value, which is what makes an import naming an option nobody has fail with
     * *that item* named rather than with the whole cell called wrong.
     *
     * The empty option list skips the `Choice` exactly as the single type skips
     * it, and for the same two readers: a definition written before [XIV-144]'s
     * refusal existed, and the probe {@see \Xivi\Core\Metadata\FieldTypeConversion}
     * builds with the options stripped off. Both have to render and save; neither
     * can be checked against a list there is not one of.
     */
    public function constraints(FieldDefinition $field): array
    {
        $choices = array_keys($this->single->optionsOf($field));

        return [
            new Assert\All([
                new Assert\Type('string'),
                ...($choices === [] ? [] : [new Assert\Choice(choices: array_map(strval(...), $choices))]),
            ]),
        ];
    }

    /**
     * The same one question a choice field is not a choice field without, with
     * the same two answers (XIV-144, XIV-127).
     *
     * **The same option names, `choices` and `list`**, and that is a promise
     * rather than a convenience. Three things read those keys and none of them
     * knows a type name: {@see \App\Controller\FieldController::PER_TYPE} draws
     * the controls, {@see \Xivi\Core\Metadata\MetadataEditor} counts what a
     * removal would strand, and `ValueListUsage` finds every field a shared list
     * reaches. Answering the question under a third name would make this type
     * unconfigurable, unremovable-from safely, and invisible to a merge, all
     * silently. {@see \App\Tests\Functional\Engine\ValueListReachesEveryTypeTest}
     * is what holds this to it.
     *
     * **Both answers, not one.** §5.29 argued that a several-valued type should
     * support no less than its single-valued neighbour, and [XIV-127] made the
     * point for the whole engine: a field's own closed set and a list the business
     * keeps are two ways of answering one question, and nothing below this line
     * can tell which it was handed.
     *
     * @return list<non-empty-list<string>>
     */
    public function needs(): array
    {
        return [[ChoiceFieldType::CHOICES, ChoiceFieldType::LIST]];
    }

    /**
     * A handful of this field's own options, for demo data (§5.17).
     *
     * Asked of the single type once per pick, so the spread comes from the same
     * generator and this file has no second opinion about what a plausible option
     * is. Duplicates collapse in {@see self::toStorage()}, so a field with two
     * options produces a short set rather than the same word twice, and the
     * canonicalisation there is what puts them in the field's order.
     *
     * Null only for a field with no options at all, which is a definition
     * predating [XIV-144]'s refusal: a required field must sample as something
     * or {@see \App\Tests\Functional\Engine\FieldTypeRoundTripTest} is round
     * tripping nothing.
     *
     * @return list<string>|null
     */
    public function sample(FieldDefinition $field, int $sequence): ?array
    {
        $picked = [];

        for ($n = 0; $n < self::SAMPLED; ++$n) {
            $one = $this->single->sample($field, $sequence);

            if ($one !== null) {
                $picked[] = $one;
            }
        }

        return $picked === [] ? null : $this->toStorage($picked, $field);
    }

    /**
     * Whatever was submitted, imported or handed over, as the stored array.
     *
     * Four shapes arrive here and each has a reason. An **array** comes from the
     * picker, from a module assembling a record and from a value read back out of
     * storage. A **string** comes from a spreadsheet cell and from a filter box,
     * and is split on {@see HoldsSeveralValues::SEPARATOR}. A bare **scalar that
     * is one option** comes from a `choice` field being converted into this type
     * (§7.2): the dry run reads every stored value through this method, so one
     * option becomes a set of one and the whole column survives, which is
     * {@see MultiReferenceFieldType::toStorage()}'s trick with a string in place
     * of an id. **Null and empty** are a field nobody filled in, and are stored
     * as nothing at all rather than as `[]`, so "absent" stays the single
     * representation of empty that every `is empty` filter already assumes.
     *
     * **De-duplicated and canonicalised into the field's option order**, which is
     * the class docblock's first decision made operational. Two saves that picked
     * the same options in different orders are one stored value, so the history
     * diff cannot report a reordering as a change; and the order is the one the
     * customer arranged rather than an alphabetical one that would put `urgent`
     * after `low`.
     *
     * **An item that is not one of the options is kept rather than dropped**, and
     * that is the load-bearing line. Dropping it would turn a spreadsheet cell
     * reading `de,tuesday,fr` into a record quietly holding two languages, and
     * §5.6's whole promise is that nothing goes in silently. Kept, it fails this
     * type's own `Choice` constraint with the item named, which reaches the person
     * importing as a line naming the sheet, the row and the field, and reaches
     * nobody at all through the form, where the picker refused it long before. It
     * is {@see IntegerFieldType}'s answer to `"12abc"` one arity up.
     *
     * Unknown items go **after** the known ones, in the order they arrived. There
     * is nowhere else for them: the canonical order is a position in a list they
     * are not on.
     *
     * @return list<mixed>|null
     */
    public function toStorage(mixed $value, FieldDefinition $field): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $items = \is_array($value) ? array_values($value) : self::split($value);

        $held = [];
        $refused = [];

        foreach ($items as $item) {
            if ($item === null || $item === '') {
                // A blank between two separators is `de,,fr`: two options and a
                // stray comma, not a third value nobody can read.
                continue;
            }

            if (!\is_scalar($item)) {
                // Not text and not a number: an object or a nested array, which
                // no caller should be handing over. Kept whole so the `Type`
                // constraint names it, rather than coerced into a string that
                // would look like something somebody imported.
                $refused[] = $item;

                continue;
            }

            // Keyed by the value itself, which is the de-duplication: picking the
            // same option twice is one value, not two.
            $held[(string) $item] = true;
        }

        $stored = [];

        // The canonical order: the field's own options, walked once, taking what
        // the record holds. Not the submitted order, which is whatever the
        // browser happened to send, and not sorted, which would be the collation
        // decision §5.29 declined to take.
        foreach (array_keys($this->single->optionsOf($field)) as $option) {
            if (isset($held[(string) $option])) {
                $stored[] = (string) $option;
                unset($held[(string) $option]);
            }
        }

        $stored = [...$stored, ...array_keys($held), ...$refused];

        return $stored === [] ? null : $stored;
    }

    /**
     * The stored array, as the option keys the application works with.
     *
     * Anything that is not a list reads as nothing, because §5.4 keeps a removed
     * field's values and a field added later with the same key meets whatever the
     * old one left behind. A page that is only trying to draw an input should
     * render it empty rather than raise.
     *
     * @return list<string>
     */
    public function fromStorage(mixed $value, FieldDefinition $field): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $held = [];

        foreach ($value as $item) {
            if (\is_scalar($item) && (string) $item !== '') {
                $held[] = (string) $item;
            }
        }

        return $held;
    }

    /** A `<select multiple>`; see the class docblock for why it is not the search endpoint. */
    public function formType(): string
    {
        return ChoiceType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        $choices = $this->single->pickerOptionsOf($field);

        return [
            // Symfony wants label => value; the definition stores value => label,
            // for {@see ChoiceFieldType::formOptions()}'s reason.
            'choices' => array_flip($choices),
            'multiple' => true,
            // ChoiceType ignores a placeholder when it may take several, and
            // leaving one set would be a line of configuration reading as though
            // it did something.
            'placeholder' => null,
            'expanded' => false,
            // The same client-side narrowing the single type offers, decided from
            // the same count, because the list is in the page either way and this
            // is the half of XIV-36 that has no endpoint behind it.
            'autocomplete' => Autocomplete::of($field)->wants(\count($choices)),
        ];
    }

    /**
     * The labels, separated by commas, in the field's current option order.
     *
     * **Plain text, like every other `display()`**, and the destinations are why:
     * a .docx marker fills with it, an export cell holds it, a record's own title
     * is built out of it, and none of those can carry a chip. §5.13.1 gave a
     * collection in an email a table because its rows have columns; a set of
     * labels has one column, and a one-column table is a list somebody has to
     * style for nothing. So `[contact.languages]` is an ordinary record marker
     * with no new grammar behind it.
     *
     * **The order is read off the field, not off the array**, which is the class
     * docblock's second half: rearranging the options in the editor changes what
     * every record reads like and rewrites no record at all.
     *
     * A value that is no longer an option prints as the raw key, exactly as
     * {@see ChoiceFieldType::display()} prints it, and for the same promise: a
     * record stored under an option since removed still has to render, and a
     * shared list deleted out from under a field must not take a module's record
     * list down with it.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        $labels = [];

        foreach ($this->inFieldOrder($value, $field) as $option => $label) {
            $labels[] = $label;
        }

        return implode(self::SHOWN, $labels);
    }

    /**
     * One chip per value, in the field's current option order ([XIV-169]).
     *
     * {@see ShowsSeveralBadges} carries the argument for chips rather than one
     * comma-separated line, and it comes down to the customer's own labels: an
     * option may be called `Zurich, CH`, so a comma between two of them is
     * ambiguous on a page in a way it is not in a spreadsheet cell.
     *
     * The colour and the picture come from the shared list's entry when the field
     * points at one, exactly as {@see ChoiceFieldType::badgeOf()} takes them, and
     * a field keeping its own options draws the neutral chip the template gives a
     * badge with no tone. That is the one place this differs from the single type,
     * which returns null there so the page draws a bare word.
     *
     * Every value, with no ceiling; see {@see ShowsSeveralBadges} for why the
     * capping belongs to whoever has the room.
     *
     * @return list<ValueBadge>
     */
    public function badgesOf(mixed $value, FieldDefinition $field): array
    {
        $list = $this->single->listOf($field);
        $badges = [];

        foreach ($this->inFieldOrder($value, $field) as $option => $label) {
            $entry = $list?->getEntry((string) $option);

            $badges[] = new ValueBadge($label, $entry?->getTone(), $entry?->getIcon());
        }

        return $badges;
    }

    /**
     * Holds this option, does not hold it, empty, filled, and no fifth.
     *
     * `Equals` is deliberately absent, on {@see MultiReferenceFieldType}'s
     * reasoning: equality against a set would have to mean "is exactly this set",
     * which a filter box holding one option cannot express, or "contains it",
     * which is what `Includes` already says without the ambiguity.
     */
    public function operators(): array
    {
        return [Operator::Includes, Operator::Excludes, Operator::IsEmpty, Operator::IsNotEmpty];
    }

    /**
     * The stored array, as something Postgres can ask a containment question of.
     *
     * `data->>'key'` hands over the array's *text*, so it has to be read back as
     * `jsonb`, and {@see \Xivi\Core\Query\QueryCompiler} applies this to the bound
     * parameter as well as to the column, so one value somebody typed becomes the
     * same one-element array the column is compared against.
     *
     * **The guard in front of the cast is not decoration**, and it is a different
     * pattern from {@see MultiReferenceFieldType::comparableSql()}'s because the
     * alphabet is different: an id is digits and an option key is lowercase ASCII,
     * digits and underscores inside quotes ({@see ChoiceFieldType::valueFor()}).
     * §5.4 keeps a removed field's values, so a `text` field that held `hello` and
     * was removed leaves a column this expression would otherwise try to parse as
     * JSON, which is an error on a list page rather than a row that does not
     * match. Anything the pattern admits is valid JSON by construction, and
     * everything else reads as the empty array and matches nothing, which is the
     * honest answer for a value this field never wrote.
     *
     * A stored value carrying an item the type refused, which only an import that
     * was itself refused can produce, falls outside the pattern and matches
     * nothing too. That is the same answer the single-reference type gives to the
     * same case, and it is the right one: a value no save would accept is not a
     * value a filter should find.
     */
    public function comparableSql(string $accessor): string
    {
        return sprintf(
            "CASE WHEN %1\$s ~ '^\\[\\s*(\"[a-z0-9_]+\"\\s*(,\\s*\"[a-z0-9_]+\"\\s*)*)?\\]$' "
            . "THEN (%1\$s)::jsonb ELSE '[]'::jsonb END",
            $accessor,
        );
    }

    /**
     * Containment, where the single choice answers equality ([XIV-169]).
     *
     * {@see Enumerates::findsHoldersBy()} has the argument. The short of it: the
     * editor counts what holds an option before letting it go, and asking that
     * with `=` against an array would count zero for ever and take the option away
     * from under every record holding it.
     */
    public function findsHoldersBy(): Operator
    {
        return Operator::Includes;
    }

    /**
     * The same options the single-valued type offers, from the same field.
     *
     * Delegated rather than reimplemented, and there is nothing to add: what a
     * field is a choice *between* does not change because it may hold several of
     * them. The customer's arrangement (§5.20) and their own definition over the
     * blueprint's (§6.1) both come along with it, and a second copy of that
     * lookup here would be a second place for the answer to drift.
     *
     * Reached through {@see PointsAtAList}, which extends {@see Enumerates}, so a
     * type that points at a list has to be able to say what is on it.
     */
    public function optionsOf(FieldDefinition $field): array
    {
        return $this->single->optionsOf($field);
    }

    /**
     * Wider than one option, because it holds several and each is a word (§5.4).
     *
     * {@see MultiReferenceFieldType}'s number and its argument: half a row is what
     * a handful of chips needs before it starts wrapping, where one select is as
     * wide as its longest label.
     */
    public function defaultWidth(): int
    {
        return 6;
    }

    /**
     * The values a record holds, keyed by option, in the field's current order.
     *
     * **The one place the display order is decided**, so `display()` and
     * `badgesOf()` cannot come apart: a page drawing chips and a document filling
     * a marker must name the same values in the same sequence or a customer
     * comparing the two has found a bug that is not there.
     *
     * A held value that is no longer an option keeps its place at the end, under
     * its raw key, which is {@see ChoiceFieldType::display()}'s promise that a
     * record outlives the option it was stored under.
     *
     * @return array<string, string> option => the label it should read as
     */
    private function inFieldOrder(mixed $value, FieldDefinition $field): array
    {
        $held = [];

        foreach ($this->fromStorage($value, $field) as $option) {
            $held[$option] = true;
        }

        $options = $this->single->optionsOf($field);
        $shown = [];

        foreach ($options as $option => $label) {
            if (isset($held[(string) $option])) {
                $shown[(string) $option] = $label;
                unset($held[(string) $option]);
            }
        }

        foreach (array_keys($held) as $option) {
            $shown[(string) $option] = (string) $option;
        }

        return $shown;
    }

    /**
     * One cell, or one filter box, as its items.
     *
     * Whitespace around a separator is somebody typing `de, fr`, which is the same
     * value and not a mistake worth refusing.
     *
     * @return list<mixed>
     */
    private static function split(mixed $value): array
    {
        if (!\is_scalar($value)) {
            // Kept whole so the constraints name it, on the same terms as an
            // unreadable item inside a list.
            return [$value];
        }

        return array_map(trim(...), explode(HoldsSeveralValues::SEPARATOR, (string) $value));
    }
}

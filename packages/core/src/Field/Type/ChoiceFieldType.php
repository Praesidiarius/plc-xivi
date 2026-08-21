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
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ValueList;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Autocompletes;
use Xivi\Core\Field\Enumerates;
use Xivi\Core\Field\HoldsSeveralValues;
use Xivi\Core\Field\PointsAtAList;
use Xivi\Core\Field\ShowsABadge;
use Xivi\Core\Field\ValueBadge;
use Xivi\Core\Query\Operator;
use Xivi\Core\ValueList\ValueLists;

/**
 * One value out of a closed set the customer defines.
 *
 * The options live in the field's own settings, so adding "Partner" beside
 * "Person" and "Company" is a definition change rather than a release — which is
 * the §5 claim applied to a value's domain rather than to its type.
 *
 * It is also what makes variants possible (§5.5): a shape names one choice field
 * as the one that decides which variant a record is, and the variants *are* that
 * field's options. No second list to keep in step.
 *
 * **Somebody may type to narrow it** (XIV-36), which is an option here and not a
 * second field type — see {@see Autocomplete} for the argument. It is the
 * cheaper half of that ticket by a wide margin: the options are a closed list in
 * the field's own settings, so they are all in the page already and narrowing
 * them is filtering something that is present. No endpoint, no permission
 * question, no ceiling, and nothing about the value changes.
 *
 * **And somebody may write them** (XIV-144), which is {@see Enumerates} and is
 * why this is the first type to say it cannot work without one of its options.
 * Until that landed the editor offered this type in its add-field select and
 * drew no control for the list, so a customer could add a choice field and never
 * be offered a choice: the constraint below skipped itself, the select rendered
 * empty, and nothing anywhere said so.
 *
 * **Or somebody may keep them beside the field instead** (XIV-127), which is
 * {@see PointsAtAList} and the second complete answer to the same question. A
 * field naming a list in `options['list']` draws that list's entries, validates
 * against them, and renders their labels — and nothing below this line can tell
 * which of the two it was handed, which is the property that made an option here
 * cheaper than a field type of its own. A field that names no list is exactly the
 * field this class has always been, byte for byte in every tenant's definitions.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ChoiceFieldType implements Autocompletes, PointsAtAList, ShowsABadge
{
    /** Stored value => label, in `options['choices']`. */
    public const string CHOICES = 'choices';

    /**
     * The key of a shared list this field takes its values from, in
     * `options['list']` (XIV-127).
     *
     * The **key** rather than the id, on the same terms as a reference's target
     * module: a key survives a database rebuilt from an export, reads as a word
     * in the definition, and is what the customer renamed the label away from.
     */
    public const string LIST = 'list';

    /**
     * How a label is turned into the key records hold, and why German.
     *
     * The same argument and the same constant as
     * {@see \Xivi\ControlPlane\Signup\SelfServiceSlug}: this product is sold into
     * a German-speaking market, and `ä ö ü ß` expanding to `ae oe ue ss` is what
     * those customers write when something has to be ASCII. A `Paletten größe`
     * becomes `paletten_groesse` rather than `paletten_grosse`, which is the
     * spelling somebody recognises if they ever see it — in an export column
     * header, in a filter URL, in an API payload.
     *
     * It is pinned rather than taken from the request for the reason that ticket
     * spells out at length: the value is permanent and the locale somebody
     * happened to have the page open in is not.
     */
    private const string TRANSLITERATION_LOCALE = 'de';

    /**
     * Where a shared list is looked up, when a field names one (XIV-127).
     *
     * **A field type with a repository in it**, which is new here and is
     * {@see ReferenceFieldType}'s precedent rather than a departure: that one has
     * held a record repository since §7.6, because a type whose values are ids
     * cannot render one without reading the record. A type whose values are a
     * list somebody keeps elsewhere is the same shape of dependency, and the
     * alternative — hydrating the entries into the definition when it is read —
     * would put a list's contents inside a definition that a save then writes
     * back, which is the copy that drifts.
     *
     * The reads are cached for the tenant's lifetime ({@see ValueLists}), so a
     * record list drawing fifty rows of one field costs one query for the list.
     */
    public function __construct(private readonly ValueLists $lists)
    {
    }

    public function key(): string
    {
        return 'choice';
    }

    public function label(): string
    {
        return 'Choice';
    }

    public function constraints(FieldDefinition $field): array
    {
        $choices = array_keys($this->optionsOf($field));

        return [
            new Assert\Type('string'),
            // An empty option list would otherwise reject everything, including
            // the empty value, which is a confusing way to say "misconfigured".
            //
            // **Still true, and no longer the way anybody gets here** (XIV-144).
            // This used to be the whole of the engine's answer to a choice field
            // with no options, and it is an answer about a *record* being saved
            // — much too late, and on the wrong screen, for something that went
            // wrong when the field was created. The refusal is where the field is
            // written now ({@see Enumerates}), so what is left below is the
            // behaviour of a definition that predates that rule or that a module
            // wrote itself: render, save, and do not pretend to check a list
            // there isn't one of.
            ...($choices === [] ? [] : [new Assert\Choice(choices: $choices)]),
        ];
    }

    /**
     * The one question a choice field is not a choice field without, and the two
     * ways of answering it (XIV-144, then XIV-127).
     *
     * "What is this a choice between?" — answered either by the field's own
     * options or by the key of a list the customer keeps beside it. One question,
     * two answers, and a field that has given either of them is finished; see
     * {@see \Xivi\Core\Field\NeedsAnAnswer::needs()} for why that is a nested
     * list rather than two entries.
     *
     * @return list<non-empty-list<string>>
     */
    public function needs(): array
    {
        return [[self::CHOICES, self::LIST]];
    }

    /**
     * One of this field's own options, never anything else.
     *
     * Which is also how a demo module gets a spread of variants for free: the
     * variant field *is* a choice (§5.5), so generating contacts produces both
     * people and companies without the generator knowing either word.
     */
    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        $choices = array_keys($this->optionsOf($field));

        if ($choices === []) {
            return null;
        }

        return (string) $choices[mt_rand(0, \count($choices) - 1)];
    }

    /**
     * One option, as the string a record holds.
     *
     * **A list arriving here is the way back from a several-valued field**
     * ([XIV-169]), and it is the only caller that can produce one: the form
     * submits a string, an import cell is a string, and a module assembling a
     * record writes one option. XIV-146's dry run is the exception, because it
     * reads every stored value through the type moving in *and* reads it back
     * again to say whether the change is reversible, so a `multi_choice` column
     * reaches this method as an array.
     *
     * Answered rather than cast, which is the whole of the change. `(string)` on
     * an array is the word `Array` and a PHP warning, so before this the report
     * a customer read about reversibility was computed from a value nobody had
     * written and a warning nobody saw. A set of **one** option is one option and
     * survives, which is what makes converting a single-valued column both ways
     * a lossless round trip; a set of **several** is kept whole in
     * {@see HoldsSeveralValues::SEPARATOR}'s spelling, the one form this engine
     * already uses for a list in a scalar cell (§5.6), so the `Choice` constraint
     * above refuses it with every value the record held named rather than
     * silently keeping one of them.
     *
     * Nothing about a `choice` field changes: no value any other caller can hand
     * over reaches this branch, and the two spellings a customer can type are
     * exactly the two they could type yesterday.
     */
    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (\is_array($value)) {
            $values = array_map(
                static fn (mixed $item): string => \is_scalar($item) ? (string) $item : '',
                array_values($value),
            );

            return \count($values) === 1 ? $values[0] : implode(HoldsSeveralValues::SEPARATOR, $values);
        }

        return \is_scalar($value) ? (string) $value : null;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function formType(): string
    {
        return ChoiceType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        // The picker's labels rather than the cell's: a shared list's child
        // entry arrives indented here and plain in `display()`, which is the
        // whole of what a hierarchy does (§5.4). A field with its own options has
        // no hierarchy and the two are the same map.
        $choices = $this->pickerOptionsOf($field);

        return [
            // Symfony wants label => value; the definition stores value => label,
            // because the value is the part that ends up in the database and so
            // is the part worth reading first.
            'choices' => array_flip($choices),
            'placeholder' => $field->isRequired() ? false : '—',
            'expanded' => false,
            // **Decided here rather than in the widget**, because everything the
            // decision needs is in the definition: how many options there are is
            // not a question about the database, the way a reference's candidate
            // count is. So this type answers it outright and the form type is
            // handed a boolean, which is also the whole of what a `choice`
            // autocomplete costs — the list is in the page either way and the
            // browser filters it.
            'autocomplete' => Autocomplete::of($field)->wants(\count($choices)),
        ];
    }

    public function display(mixed $value, FieldDefinition $field): string
    {
        if (!\is_string($value) || $value === '') {
            return '';
        }

        // The label if it is still an option, the raw value if it is not: a
        // record stored under an option since removed still has to render. That
        // promise is what lets a shared list be deleted out from under a field
        // without taking a module's record list down with it.
        return $this->optionsOf($field)[$value] ?? $value;
    }

    /**
     * The colour and the picture, when the value has one (XIV-127).
     *
     * {@see ShowsABadge} has the argument for asking the *field* rather than
     * switching on its type. Null all the way down for a field with its own
     * options: a plain `choice` field has no colours to carry, so it produces no
     * badge and every page draws it exactly as it drew it before this ticket.
     */
    public function badgeOf(mixed $value, FieldDefinition $field): ?ValueBadge
    {
        $list = $this->listOf($field);

        if ($list === null || !\is_string($value) || $value === '') {
            return null;
        }

        $entry = $list->getEntry($value);

        if ($entry === null || ($entry->getTone() === null && $entry->getIcon() === null)) {
            // A list whose entries carry no colour and no picture is a list of
            // words, and a badge around a word is furniture. Null is the
            // caller's cue to draw the label the ordinary way.
            return null;
        }

        return new ValueBadge($entry->getLabel(), $entry->getTone(), $entry->getIcon());
    }

    public function operators(): array
    {
        return [Operator::Equals, Operator::NotEquals, Operator::IsEmpty, Operator::IsNotEmpty];
    }

    /** Already text in the payload, and compared as the stored value, not the label. */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    /**
     * Equality, because this field holds exactly one option ([XIV-169]).
     *
     * The answer this engine assumed everywhere until a second type started
     * enumerating, written down now that it is one of two.
     * {@see Enumerates::findsHoldersBy()} has the argument for asking rather than
     * assuming; nothing about *this* type's answer changed, which is the whole
     * of what a caller sees.
     */
    public function findsHoldersBy(): Operator
    {
        return Operator::Equals;
    }

    /**
     * The field's **own** options, and deliberately only those.
     *
     * Unchanged by XIV-127, and left static on purpose. Two callers want exactly
     * this question and would be wrong to be given the other one: the editor
     * compares the options a field *has* with the ones a save is about to give
     * it ({@see \Xivi\Core\Metadata\MetadataEditor}), and a shape reads its
     * variants off the variant field (§5.5) — which is always a module's own
     * field and therefore never points at a shared list.
     *
     * Everything that renders, validates or generates a value asks
     * {@see self::optionsOf()} instead, because those callers must not be able
     * to tell where the list came from.
     *
     * @return array<string, string> value => label
     */
    public static function choicesOf(FieldDefinition $field): array
    {
        return self::clean($field->getOption(self::CHOICES, []));
    }

    /**
     * What this field is actually a choice between: the shared list if it names
     * one, its own options otherwise (XIV-127).
     *
     * **The one place the two answers meet**, which is what keeps every caller
     * below it — the constraint, the widget, the sample generator, the display —
     * ignorant of which it was handed. A field naming a list nothing can find
     * falls through to its own options, which for such a field is empty: the
     * same state a `choice` field with no options has always been in, rendering
     * what records hold and validating nothing, rather than a page that throws
     * because somebody deleted a list (§5.4).
     *
     * @return array<string, string> value => label, as a cell should read them
     */
    public function optionsOf(FieldDefinition $field): array
    {
        $list = $this->listOf($field);

        return $list === null ? self::choicesOf($field) : $list->labels();
    }

    /**
     * The same, with a hierarchy's indentation in the labels.
     *
     * Split from `optionsOf()` rather than parameterised because the two have
     * different readers and one of them is a `<select>`: an indent belongs in a
     * dropdown of forty regions and is noise in a table column
     * ({@see ValueList::asChoices()}).
     *
     * @return array<string, string> value => label, as a picker should offer them
     */
    public function pickerOptionsOf(FieldDefinition $field): array
    {
        $list = $this->listOf($field);

        return $list === null ? self::choicesOf($field) : $list->asChoices();
    }

    /**
     * The shared list this field points at, or null for a field that keeps its
     * own options.
     *
     * Null is the answer for both "names no list" and "names a list this tenant
     * has not got", and collapsing those two is deliberate: the difference
     * matters to the editor, which refuses to write either, and to nothing that
     * draws a page.
     */
    public function listOf(FieldDefinition $field): ?ValueList
    {
        $key = self::listKeyOf($field);

        return $key === '' ? null : $this->lists->find($key);
    }

    /**
     * The key of the shared list this field names, or the empty string.
     *
     * Static and beside {@see self::choicesOf()} for the reason that one is:
     * the editor has to compare the list a field *names* with the one a save is
     * about to give it, and that comparison must not go anywhere near a
     * database. {@see ReferenceFieldType::targetModule()} is the same method for
     * the same job.
     */
    public static function listKeyOf(FieldDefinition $field): string
    {
        $key = $field->getOption(self::LIST);

        return \is_string($key) ? $key : '';
    }

    /**
     * The same list read out of a raw options value rather than a definition
     * (XIV-144).
     *
     * Split out because the editor has to compare the list a field *has* with
     * the list a save is *about to give it*, and the second one is an array in a
     * request rather than anything a `FieldDefinition` can be asked for. One
     * reading of what a stored `choices` means, used by both, so "which options
     * are being removed" cannot be answered differently from "which options
     * exist".
     *
     * Tolerant in the same way it has always been: anything that is not an array
     * is no options at all, and a label that is not scalar falls back to the
     * value, because a definition that has been hand-edited into nonsense should
     * still render.
     *
     * @return array<string, string> value => label
     */
    public static function clean(mixed $choices): array
    {
        if (!\is_array($choices)) {
            return [];
        }

        $clean = [];
        foreach ($choices as $value => $label) {
            $clean[(string) $value] = \is_scalar($label) ? (string) $label : (string) $value;
        }

        return $clean;
    }

    /**
     * The key a new option's records will hold, derived from the label somebody
     * typed (XIV-144).
     *
     * **Derived once and then frozen**, which is the whole of the value/label
     * split made operational. The customer types "Pallet"; every record from
     * then on holds `pallet`; renaming the option to "Palette", or to "Palette
     * (EUR)", changes what the page says and touches no record at all. Asking
     * for the key as well would be asking somebody who wants a seventh unit to
     * understand what a key is, and the honest way to phrase that question —
     * "this cannot be changed afterwards" — is a sentence the shipped options
     * never had to make anybody read.
     *
     * The trade is that a *typo* in a label is permanent in the key. It is the
     * right trade: nobody but an export column ever sees a key, the label is
     * fixable in the editor, and the alternative is a rename that silently
     * orphans records — which is the one outcome §5.4 has refused everywhere
     * else.
     *
     * Boring by construction, the same way a field key is
     * ({@see \Xivi\Core\Metadata\MetadataEditor::KEY_PATTERN}): lowercase ASCII,
     * digits and underscores, starting with a letter. Anything that transliterates
     * to nothing at all — a label written entirely in an alphabet ASCII has no
     * answer for — falls back to `option`, and the uniquifier below turns the
     * second of those into `option_2`. A fallback rather than a refusal, because
     * a customer naming their options in Greek is doing nothing wrong and the
     * key is not the part they read.
     *
     * @param array<string, mixed> $taken the options that already exist, so two
     *                                    labels that slug the same way do not
     *                                    become one option
     */
    public static function valueFor(string $label, array $taken = []): string
    {
        $value = new AsciiSlugger(self::TRANSLITERATION_LOCALE)->slug($label, '_')->lower()->toString();
        // The slugger keeps a few characters that are legal in a URL and not in
        // an identifier — a dot in "3.5 m", a plus in "A+" — so the pattern the
        // rest of the engine uses is applied rather than assumed.
        $value = trim((string) preg_replace('/[^a-z0-9_]+/', '_', $value), '_');

        if ($value === '' || ctype_digit($value[0])) {
            // A key has to start with a letter, and "2 pieces" is a perfectly
            // ordinary label. Prefixing beats dropping the digits, which would
            // turn "2 pieces" and "3 pieces" into one option.
            $value = $value === '' ? 'option' : 'option_' . $value;
        }

        if (!\array_key_exists($value, $taken)) {
            return $value;
        }

        $suffix = 2;

        while (\array_key_exists($value . '_' . $suffix, $taken)) {
            ++$suffix;
        }

        return $value . '_' . $suffix;
    }

    /**
     * A select is as wide as its longest option, which is a label rather than a
     * sentence. Stretching it to the page makes the arrow float away from the
     * word it belongs to.
     */
    public function defaultWidth(): int
    {
        return 4;
    }
}

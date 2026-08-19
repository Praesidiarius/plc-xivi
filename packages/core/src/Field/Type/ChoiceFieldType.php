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
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Autocompletes;
use Xivi\Core\Field\Enumerates;
use Xivi\Core\Query\Operator;

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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ChoiceFieldType implements Autocompletes, Enumerates
{
    /** Stored value => label, in `options['choices']`. */
    public const string CHOICES = 'choices';

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
        $choices = array_keys(self::choicesOf($field));

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
     * The one option a choice field is not a choice field without (XIV-144).
     *
     * @return list<string>
     */
    public function needs(): array
    {
        return [self::CHOICES];
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
        $choices = array_keys(self::choicesOf($field));

        if ($choices === []) {
            return null;
        }

        return (string) $choices[mt_rand(0, \count($choices) - 1)];
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
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
        $choices = self::choicesOf($field);

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
        // record stored under an option since removed still has to render.
        return self::choicesOf($field)[$value] ?? $value;
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

    /** @return array<string, string> value => label */
    public static function choicesOf(FieldDefinition $field): array
    {
        return self::clean($field->getOption(self::CHOICES, []));
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

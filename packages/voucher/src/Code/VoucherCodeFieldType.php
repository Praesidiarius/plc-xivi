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

namespace Xivi\Voucher\Code;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Query\Operator;
use Xivi\Voucher\VoucherModule;

/**
 * The field type behind a voucher's code, and the one place case is decided
 * (XIV-103).
 *
 * ### Why a field type at all
 *
 * Because {@see FieldType::toStorage()} is the engine's single normalisation
 * seam, and a rule about the *shape of a value* has nowhere better to live. The
 * interface's own docblock says it: a type owns validation, storage, the form
 * control and the display, and adding one is "one class and no configuration".
 * Three callers run every value through `toStorage()` before doing anything with
 * it — {@see \Xivi\Core\Validation\RecordValidator} before validating,
 * {@see \Xivi\Core\Record\RecordRepository} before writing, and
 * {@see \Xivi\Core\Query\QueryCompiler} before comparing — so folding here folds
 * for the form, the spreadsheet import, the unique index and every lookup by
 * code that [XIV-104] will make, without any of them being told.
 *
 * The alternative was an option on `text` (`case: upper`) handled in
 * {@see \Xivi\Core\Field\Type\TextFieldType}. It would have worked and it puts a
 * voucher's rules in core, where the next reader has no way of knowing why a
 * text field grew a case setting. This module owns the rule, so this module
 * ships the type.
 *
 * ### What that costs, said out loud
 *
 * The registry is global, so "Voucher code" appears in the metadata editor's
 * type dropdown for **every** module in every tenant, including tenants that
 * have never installed this one. That is a real wart. Hiding it would need the
 * engine to learn which module may offer which type — a concept it does not have
 * and should not grow for the sake of one dropdown entry — and the type is not
 * harmful anywhere it might be picked: it is a short upper-cased identifier,
 * which is a reasonable thing to want on a record that is not a voucher. It is
 * written down here rather than discovered later.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherCodeFieldType implements FieldType
{
    /**
     * The refusal somebody reads when they type something a code cannot be.
     *
     * A sentence rather than a key, because Symfony translates a constraint
     * message through the `validators` domain keyed by its English text — see
     * `translations/validators.en.yaml`, which is where the German lives.
     *
     * It names the character set, shows an example, and answers the question the
     * refusal itself provokes: no, you did not have to type it in capitals.
     */
    public const string MALFORMED = 'A code is made of A-Z, 0-9 and single hyphens, like GIVE-10. '
        . 'Lower case is fine — it is stored in capitals either way.';

    /**
     * Stored in every `field_definition` row that uses it, so it is a constant
     * rather than a literal: the deriver asks for fields of this type by name,
     * and a key spelled twice is a key that can be spelled differently twice.
     */
    public const string KEY = 'voucher_code';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Voucher code';
    }

    /**
     * Checked against the **folded** value, which is what makes the regex able to
     * name only capitals.
     *
     * `RecordValidator` normalises before it validates, on purpose and in its own
     * words: "values are validated in the shape they will be stored in".
     * Otherwise this pattern would have to accept both cases and the storage
     * would have to fold afterwards, which is two rules that can drift.
     */
    public function constraints(FieldDefinition $field): array
    {
        return [
            new Assert\Type('string'),
            new Assert\Length(min: VoucherCode::MIN_LENGTH, max: VoucherCode::MAX_LENGTH),
            new Assert\Regex(pattern: VoucherCode::PATTERN, message: self::MALFORMED),
        ];
    }

    /**
     * A code for a demo voucher, generated exactly as a real one is.
     *
     * No `$sequence` in it, unlike {@see \Xivi\Core\Field\Type\TextFieldType},
     * which appends one to keep a vocabulary of thirty names unique across fifty
     * thousand records. This does not need the crutch: the alphabet is thirty
     * characters in eight positions, so ten thousand demo vouchers collide with
     * probability around one in thirteen thousand — and a demo tenant that lost
     * that coin toss would be refused by the unique index rather than storing a
     * duplicate, which is the failure that is safe to have.
     *
     * Never null, even though the field is optional. An empty demo code would be
     * filled in by {@see AssignsVoucherCodes} on the next save and, until then,
     * would show a blank where a demo tenant's whole point is showing what the
     * module looks like full.
     */
    public function sample(FieldDefinition $field, int $sequence): string
    {
        return VoucherCode::generate();
    }

    /**
     * **The one fold.** Everything this class exists for is on this line.
     *
     * Declared `mixed` rather than `?string` for the same reason
     * {@see \Xivi\Core\Field\Type\DateFieldType} is: whatever this is handed
     * that is not a string comes back untouched, and narrowing the return type
     * would force a cast in exactly the case where casting is the wrong thing to
     * do.
     *
     * @see VoucherCode::normalize() for why upper case and why here
     */
    public function toStorage(mixed $value, FieldDefinition $field): mixed
    {
        // Anything that is not a string is handed back untouched rather than
        // cast, for the reason IntegerFieldType gives about "12abc": the
        // validator's job is to refuse it, and silently turning it into
        // something plausible would store a code nobody typed.
        if ($value !== null && !\is_string($value)) {
            return $value;
        }

        return VoucherCode::normalize($value);
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function formType(): string
    {
        return TextType::class;
    }

    /**
     * A text box that knows it is holding a code.
     *
     * `autocapitalize` and `spellcheck` are hints to a phone keyboard, which
     * otherwise offers to correct `GIVE-10` into something else entirely. They
     * change nothing server-side — {@see toStorage()} is what is true — and they
     * remove the most common way a mobile browser makes a code wrong before it is
     * ever submitted.
     *
     * The help text is this module's, in this module's catalogue, so it is set
     * with a `translation_domain` of its own. That domain also applies to the
     * label, which is harmless: a label is a literal string seeded into the
     * customer's definitions at install time (§6.1), so the translator looks it
     * up, misses, and hands back the string it was given — including when the
     * customer has since renamed the field to something no catalogue has heard
     * of.
     */
    public function formOptions(FieldDefinition $field): array
    {
        return [
            'attr' => [
                'maxlength' => VoucherCode::MAX_LENGTH,
                'placeholder' => 'GIVE-10',
                'autocapitalize' => 'characters',
                'autocomplete' => 'off',
                'spellcheck' => 'false',
            ],
            'help' => 'field.code_help',
            'translation_domain' => VoucherModule::KEY,
        ];
    }

    public function display(mixed $value, FieldDefinition $field): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * `Contains` is offered and `GreaterThan` is not.
     *
     * Searching for the vouchers whose code starts with `SUMMER` is a real
     * question somebody asks of a list. Asking which codes sort after `M` is not
     * a question about vouchers at all, and offering it would put a control on a
     * filter page that can only ever produce nonsense.
     */
    public function operators(): array
    {
        return [
            Operator::Equals,
            Operator::NotEquals,
            Operator::StartsWith,
            Operator::Contains,
            Operator::IsEmpty,
            Operator::IsNotEmpty,
        ];
    }

    /** Already text in the payload, and already folded, so nothing to convert. */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    /**
     * A quarter of a row. A code is nine characters and sits beside the kind it
     * belongs to; giving it half a row would leave most of that half empty.
     */
    public function defaultWidth(): int
    {
        return 3;
    }
}

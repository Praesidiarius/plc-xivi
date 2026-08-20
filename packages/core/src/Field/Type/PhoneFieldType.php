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

use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\AssumesACountry;
use Xivi\Core\Phone\DiallablePhoneNumber;
use Xivi\Core\Phone\PhoneNumbers;
use Xivi\Core\Query\Operator;

/**
 * A phone number: one number, one stored value, however it was typed (XIV-114).
 *
 * Before this, phone numbers lived in `text` fields, so `+41 79 123 45 67`,
 * `0791234567` and `079 123 45 67` were three different values for one number. A
 * search found one of them. A duplicate check found none of them. An export was
 * whatever each person had happened to type, and the day somebody wanted to send
 * an SMS from any of it, none of it was a number.
 *
 * ### `toStorage()` is the seam, and that is the entire design
 *
 * The same argument {@see \Xivi\Voucher\Code\VoucherCodeFieldType} makes about
 * case, one step harder. Three callers run every value through `toStorage()`
 * before doing anything with it — {@see \Xivi\Core\Validation\RecordValidator}
 * before validating, {@see \Xivi\Core\Record\RecordRepository} before writing and
 * {@see \Xivi\Core\Query\QueryCompiler} before comparing — so normalising here
 * normalises for the form, the spreadsheet import, the unique index and every
 * filter anybody ever types, without one of them being told. **The form and the
 * importer and the query compiler cannot disagree about what a phone number is,
 * because none of them has an opinion.**
 *
 * The consequences of that are taken deliberately rather than discovered:
 *
 *  * **`unique` starts working.** [XIV-109]'s index is over the stored string, so
 *    two people entering one number differently now collide the way they always
 *    should have. That is a behaviour change on any tenant that ticks the box.
 *  * **An import of existing data will refuse rows.** A file of numbers typed by
 *    hand over ten years contains some that are not numbers, and they arrive as
 *    refusals now instead of as strings. That is correct and it is still a
 *    surprise, so the refusal names the value *and* the country it was read
 *    against — see {@see DiallablePhoneNumber}.
 *  * **Nothing rewrites what is already stored, and nothing ever will without
 *    being asked.** When this type shipped, changing a `text` field to it was
 *    not possible at all (§5.4), so a customer whose `phone` was a text field
 *    kept one. [XIV-146] built the conversion and did not weaken that: the
 *    customer asks for it, on their own field, having read what every value in
 *    the column turns into and which of them this type refuses. Nothing on an
 *    upgrade and nothing on a deploy converts anybody. What this type governs is
 *    still fields that are of this type, however they came to be.
 *
 * ### Extensions are refused, and that is a decision
 *
 * `+41 44 668 18 00 ext. 12` is a real thing people type, and there were three
 * options: keep it in the value, put it in a second field, or refuse it. It is
 * refused, and the argument is not taste — it is that **E.164 has no room for an
 * extension and `format(E164)` drops it without saying so.** Keeping it in the
 * value would mean either storing something that is not E.164, which gives up the
 * canonical form this whole type exists for, or storing the switchboard number
 * twelve times and calling it twelve different people: on a `unique` field the
 * twelfth colleague is refused for a reason nothing on screen can explain, and on
 * an ordinary one the digits that made the record useful are gone.
 *
 * A second field is the right answer and it is one the customer already has: the
 * metadata editor adds a text field called "Extension" without anybody deploying
 * anything (§5.4). Building it into this type would have meant a second column in
 * the payload for a single field, which the engine has no shape for. So the
 * refusal says what to do, and what it says is "put it in a field of its own".
 *
 * ### Which country, and why there is no picker
 *
 * `079 123 45 67` is only a number if you know where it was dialled — the same
 * digits are a valid Swiss mobile and a valid German landline. The country comes
 * from {@see \Xivi\Core\Region\InstanceRegion}, which is §8.6's answer reached
 * through [XIV-50]'s chain, with a per-field override for the one case the chain
 * cannot express ({@see \Xivi\Core\Phone\PhoneRegion}). No fourth country setting
 * was added, which was the thing to avoid.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PhoneFieldType implements AssumesACountry
{
    /**
     * Stored in every `field_definition` row that uses it, so it is a constant
     * rather than a literal — the same call {@see \Xivi\Voucher\Code\VoucherCodeFieldType}
     * makes, for the same reason: a key spelled twice can be spelled differently
     * twice.
     */
    public const string KEY = 'phone';

    /**
     * The country demo data pretends an installation is in when nothing says.
     *
     * **Demo data only, and never storage.** A tenant that has not filled in its
     * profile has no region, and the honest answer for a *customer's* value is to
     * refuse anything without a country code — but a demo tenant exists to show
     * what the module looks like full, and a column of empty phone numbers shows
     * nothing. So generated data falls back to this project's home market and
     * says so, rather than quietly teaching the rest of the engine that there is
     * a default country somewhere.
     */
    private const string DEMO_REGION = 'CH';

    public function __construct(private readonly PhoneNumbers $numbers)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Phone number';
    }

    /**
     * Checked against the **stored** value, which is what lets the message be
     * specific.
     *
     * `RecordValidator` normalises before it validates, on purpose and in its own
     * words, so a value that reaches this either is E.164 already or is the
     * string `toStorage()` gave up on. The length bound is a guard on the
     * database rather than a rule about numbers — E.164 caps at fifteen digits, so
     * anything approaching this is a refusal already on its way.
     */
    public function constraints(FieldDefinition $field): array
    {
        return [
            new Assert\Type('string'),
            new Assert\Length(max: 40),
            new DiallablePhoneNumber(region: $this->numbers->regionFor($field)),
        ];
    }

    /**
     * **The one normalisation.** Everything this class exists for is in these
     * four lines.
     *
     * Anything that cannot be read comes back **unchanged** rather than as null,
     * and that is the load-bearing choice. Null would mean "no value", so a
     * mistyped number would be saved as an empty field: the record would save
     * cleanly, the customer would see a blank where they had typed something, and
     * nothing anywhere would have said no. Handing the original string back puts
     * it in front of {@see DiallablePhoneNumber}, which refuses it by name.
     *
     * Non-strings are handed back untouched for the reason `IntegerFieldType`
     * gives about `"12abc"`: the validator's job is to refuse them, and casting
     * would invent a plausible value nobody typed.
     */
    public function toStorage(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_string($value)) {
            return $value;
        }

        return $this->numbers->read($value, $this->numbers->regionFor($field))->e164 ?? trim($value);
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * A plausible number for this field's country, different every time.
     *
     * Google ships one example number per country, so a naive implementation
     * would give fifty thousand demo contacts the same phone number — which on a
     * field somebody has marked `unique` is fifty thousand collisions rather than
     * merely dull data. {@see PhoneNumbers::example()} varies the last digits from
     * the sequence and checks the result is still diallable before offering it.
     *
     * Returns E.164 rather than a national spelling, so it is what `toStorage()`
     * would have produced anyway and the generated value survives the round trip
     * through it (see {@see \Xivi\Core\Demo\FieldSampler}).
     */
    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        if (!$field->isRequired() && mt_rand(1, 10) === 1) {
            return null;
        }

        return $this->numbers->example($this->numbers->regionFor($field) ?? self::DEMO_REGION, $sequence);
    }

    public function formType(): string
    {
        return TelType::class;
    }

    /**
     * A telephone box that knows which country it is in.
     *
     * The placeholder is a **real number from that country**, formatted the way
     * that country writes it — so a Dutch customer is shown a Dutch number rather
     * than a Swiss one, and nobody has to guess whether the leading zero belongs.
     * The help text says which country numbers without a country code will be
     * read as, because that is the single fact that makes a later refusal
     * comprehensible, and it is the one thing the field cannot show by example.
     *
     * `inputmode="tel"` is a hint to a phone keyboard and changes nothing
     * server-side; {@see toStorage()} is what is true.
     *
     * The `translation_domain` is the engine's own, which also applies to the
     * label — harmless for the same reason it is harmless on a voucher code: a
     * label is a literal seeded into the customer's definitions at install time
     * (§6.1), so the translator looks it up, misses, and hands back the string it
     * was given, including after the customer has renamed the field.
     */
    public function formOptions(FieldDefinition $field): array
    {
        $region = $this->numbers->regionFor($field);
        $example = $this->numbers->example($region);

        return [
            'attr' => [
                'maxlength' => 40,
                'placeholder' => $example === null ? '+41 79 123 45 67' : $this->numbers->display($example, $region),
                'inputmode' => 'tel',
                'autocomplete' => 'tel',
            ],
            'help' => $region === null ? 'phone.help_no_country' : 'phone.help_country',
            'help_translation_parameters' => ['%country%' => self::countryName($region)],
            'translation_domain' => 'xivi',
        ];
    }

    /**
     * The number as the person reading it would write it (§8.4.2).
     *
     * **E.164 is for storing, not for reading.** `+41791234567` is unambiguous and
     * nobody writes it on a business card; the reader's own country decides
     * which half of that trade they get — national where the number is local,
     * international where it is not.
     *
     * The reader's country comes from `\Locale::getDefault()`, which is not a
     * shortcut but the whole [XIV-50] chain arriving where it already goes:
     * `FormattingLocale` composes the person's language with their region and
     * `Request::setLocale()` sets PHP's default from it, which is exactly why
     * {@see DateFieldType::display()} and {@see CurrencyFieldType::display()} ask
     * the same question the same way. Core still does not know what a user is.
     *
     * A console command has no reader and lands on a locale with no region, which
     * falls to international — the right answer for output nobody is reading over
     * somebody's shoulder.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        if (!\is_string($value) || $value === '') {
            return '';
        }

        $region = \Locale::getRegion(\Locale::getDefault());

        return $this->numbers->display($value, $region === '' ? null : $region);
    }

    /**
     * `StartsWith` is the interesting one, and it is free.
     *
     * Because everything is stored E.164, "every number in Switzerland" is
     * `starts with +41` — a question the `text` field this replaces could not
     * answer at all, since half the rows would have been written `079 …`.
     * `GreaterThan` is not offered: which numbers sort after `+4179` is not a
     * question about telephones.
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

    /** Already text in the payload, and already canonical, so nothing to convert. */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    /**
     * A third of a row. A number is sixteen characters at its longest and sits
     * beside an email address, which asks for six.
     */
    public function defaultWidth(): int
    {
        return 4;
    }

    /** The country as somebody says it, in the language being read. */
    private static function countryName(?string $region): string
    {
        if ($region === null) {
            return '';
        }

        $name = \Locale::getDisplayRegion('und-' . $region, \Locale::getDefault());

        return $name === '' ? strtoupper($region) : $name;
    }
}

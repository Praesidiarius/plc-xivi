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

use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Money\InstanceCurrency;
use Xivi\Core\Query\Operator;

/**
 * An amount of money, in the currency this installation works in (XIV-11).
 *
 * **Symfony's MoneyType does the work**, and the Bootstrap theme's `money_widget`
 * draws the input group around it — currency in a `.input-group-text` beside the
 * field, on whichever side the reader's locale puts it. `currency: false` when
 * nobody has chosen one, which renders the plain input the pattern asks for.
 * Nothing here is hand-rolled: a widget written to sit next to Symfony's own is a
 * widget that has to be kept next to it.
 *
 * **Stored as a decimal string, never a float.** 19.90 is not representable in
 * binary floating point, and a price that reads back a hundredth of a cent short
 * is the kind of bug that surfaces on an invoice rather than in a test. JSONB
 * holds the string, MoneyType is asked for `input: 'string'` so the form hands
 * one back, and `comparableSql` casts only for ordering.
 *
 * **The currency is not stored with it.** It comes from the tenant profile
 * (§8.6) and belongs to the installation, so a column of prices adds up. Per
 * record it would need exchange rates behind it to mean anything, which is a
 * feature and not a field option.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CurrencyFieldType implements FieldType
{
    /** Cents. Currencies with three decimals are a problem for the day one arrives. */
    public const int SCALE = 2;

    public function __construct(private readonly InstanceCurrency $currency)
    {
    }

    public function key(): string
    {
        return 'currency';
    }

    public function label(): string
    {
        return 'Price';
    }

    public function constraints(FieldDefinition $field): array
    {
        $constraints = [new Assert\Type('numeric')];

        $min = $field->getOption('min');
        $max = $field->getOption('max');

        if (is_numeric($min) || is_numeric($max)) {
            $constraints[] = new Assert\Range(
                min: is_numeric($min) ? (float) $min : null,
                max: is_numeric($max) ? (float) $max : null,
            );
        }

        return $constraints;
    }

    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        if (!$field->isRequired() && mt_rand(1, 10) === 1) {
            return null;
        }

        $min = is_numeric($field->getOption('min')) ? (float) $field->getOption('min') : 1.0;
        $max = is_numeric($field->getOption('max')) ? (float) $field->getOption('max') : 999.0;

        // In cents, so the generated value lands on one the field could hold —
        // and inside whatever range this definition allows, like every other
        // type's sample.
        return $this->stored(mt_rand((int) round($min * 100), (int) round(max($min, $max) * 100)) / 100);
    }

    /**
     * A decimal string with the scale spelled out, so "19.9" and "19.90" are one
     * stored value rather than two.
     */
    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Anything that is not a number is left as it came: refusing it is the
        // validator's job, and casting "12abc" would store a price nobody typed.
        return is_numeric($value) ? $this->stored((float) $value) : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function formType(): string
    {
        return MoneyType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        $options = [
            // False, not null: MoneyType reads it as "no currency in the
            // pattern" and renders a bare input, which is the honest widget for
            // an installation that has not chosen one (§8.6).
            'currency' => $this->currency->code() ?? false,
            'scale' => self::SCALE,
            // The stored value is a decimal string and comes back as one, so
            // nothing on this path is ever a float.
            'input' => 'string',
            'divisor' => 1,
            // Thousands separated on the figures nobody types (XIV-47). A
            // derived field is `disabled`, so its value is never parsed back
            // out of the view — which is what makes grouping free here and a
            // risk on a field somebody edits.
            'grouping' => $field->isDerived(),
        ];

        $attr = [];

        foreach (['min', 'max'] as $bound) {
            if (is_numeric($field->getOption($bound))) {
                $attr[$bound] = $field->getOption($bound);
            }
        }

        return $attr === [] ? $options : [...$options, 'attr' => $attr];
    }

    /**
     * The amount, named in the language being read: "CHF 19.90", "19,90 CHF".
     *
     * The same convention the widget follows, from the same ICU data — so a
     * price does not change sides between the list it is read in and the form it
     * is edited in.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $code = $this->currency->code();

        if ($code === null) {
            return $this->formatted((float) $value);
        }

        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::CURRENCY);

        // False on a currency ICU does not recognise, which is possible: the
        // profile stores a code, and a build's ICU data is whatever it shipped.
        return $formatter->formatCurrency((float) $value, $code)
            ?: sprintf('%s %s', $code, $this->formatted((float) $value));
    }

    public function operators(): array
    {
        return [
            Operator::Equals,
            Operator::NotEquals,
            Operator::AtLeast,
            Operator::AtMost,
            Operator::GreaterThan,
            Operator::LessThan,
            Operator::IsEmpty,
            Operator::IsNotEmpty,
        ];
    }

    /**
     * Cast, or 100 would sort before 9 and "at most 20" would compare strings.
     * Numeric rather than a float type: it is exact, which is the whole point of
     * storing the string.
     */
    public function comparableSql(string $accessor): string
    {
        return sprintf('(%s)::numeric', $accessor);
    }

    /**
     * The stored form: a plain decimal string, the same in every language.
     *
     * **Not for showing anybody.** This is what goes in the database and what
     * the validator checks, so it has a dot and no separators wherever it is
     * read — `is_numeric()` says no to `1,000.00`, and rightly.
     *
     * It was called `amount()` and did this job *and* the display one, which is
     * how localizing "the formatting" quietly localized the storage and made
     * every save refuse its own totals (XIV-47). The two have different jobs and
     * now have different names.
     */
    private function stored(float $value): string
    {
        return number_format($value, self::SCALE, '.', '');
    }

    /**
     * The reading form, when there is no currency to put beside it.
     *
     * Reached in two ordinary cases — an installation that has not chosen a
     * currency (§8.6), and one whose code this build's ICU data does not know —
     * so it is what every tenant sees until they fill in their profile. It is
     * grouped and it uses the reader's separators, because a number without a
     * symbol is still a number.
     */
    private function formatted(float $value): string
    {
        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, self::SCALE);

        return $formatter->format($value) ?: $this->stored($value);
    }

    /**
     * Wider than a plain number: there is a symbol beside it and the figures run
     * to thousands more often than a count does.
     */
    public function defaultWidth(): int
    {
        return 4;
    }
}

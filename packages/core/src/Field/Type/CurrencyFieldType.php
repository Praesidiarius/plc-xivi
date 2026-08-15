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
use Xivi\Core\Field\FieldType;
use Xivi\Core\Form\Type\MoneyAmountType;
use Xivi\Core\Money\InstanceCurrency;
use Xivi\Core\Query\Operator;

/**
 * An amount of money, in the currency this installation works in (XIV-11).
 *
 * **Stored as a decimal string, never a float.** 19.90 is not representable in
 * binary floating point, and a price that reads back a hundredth of a cent short
 * is the kind of bug that surfaces on an invoice rather than in a test. JSONB
 * holds the string, `comparableSql` casts it for ordering, and nothing in
 * between ever makes it a float.
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
        return $this->amount(mt_rand((int) round($min * 100), (int) round(max($min, $max) * 100)) / 100);
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
        return is_numeric($value) ? $this->amount((float) $value) : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function formType(): string
    {
        return MoneyAmountType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        $attr = [];

        foreach (['min', 'max'] as $bound) {
            if (is_numeric($field->getOption($bound))) {
                $attr[$bound] = $field->getOption($bound);
            }
        }

        return $attr === [] ? [] : ['attr' => $attr];
    }

    /**
     * The amount, named in the language being read: "CHF 19.90", "19,90 CHF".
     *
     * Reading is not entering, so this follows the reader's conventions where
     * the widget deliberately does not (see MoneyAmountType) — a list is prose,
     * and a form is somebody typing.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $code = $this->currency->code();

        if ($code === null) {
            return $this->amount((float) $value);
        }

        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::CURRENCY);

        // False on a currency ICU does not recognise, which is possible: the
        // profile stores a code, and a build's ICU data is whatever it shipped.
        return $formatter->formatCurrency((float) $value, $code)
            ?: sprintf('%s %s', $code, $this->amount((float) $value));
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

    private function amount(float $value): string
    {
        return number_format($value, self::SCALE, '.', '');
    }
}

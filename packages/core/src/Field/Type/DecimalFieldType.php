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

use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Query\Operator;

/**
 * A number with a fraction, and no opinion about money (XIV-22).
 *
 * The engine had `integer` and it had `currency`, and nothing in between — so an
 * order line could sell three lamps and could not sell two and a half hours,
 * because the only field type carrying a fraction printed a currency symbol
 * beside it.
 *
 * **Stored as a decimal string, never a float**, for the same reason `currency`
 * is: 2.5 survives a round trip and 0.1 + 0.2 does not. That matters more here
 * than it looks, because a quantity is usually one side of a multiplication and
 * the error compounds into the money.
 *
 * **How many places is the field's own setting.** A quantity of hours wants two
 * and a weight in kilos might want three; a type that decided for everybody
 * would be wrong for half of them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DecimalFieldType implements FieldType
{
    public const string SCALE = 'scale';

    /** Enough for hours and for most quantities; the field says otherwise if it needs to. */
    public const int DEFAULT_SCALE = 2;

    public function key(): string
    {
        return 'decimal';
    }

    public function label(): string
    {
        return 'Number with decimals';
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
        $max = is_numeric($field->getOption('max')) ? (float) $field->getOption('max') : 100.0;

        // Generated at the field's own scale, so a value it produces is one the
        // same definition would accept back.
        $steps = 10 ** self::scaleOf($field);

        return $this->round(mt_rand((int) ($min * $steps), (int) (max($min, $max) * $steps)) / $steps, $field);
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Anything that is not a number is left as it came: refusing it is the
        // validator's job, and casting "3 boxes" to 3 would store a quantity
        // nobody typed.
        return is_numeric($value) ? $this->round((float) $value, $field) : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function formType(): string
    {
        return NumberType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        $options = [
            'scale' => self::scaleOf($field),
            // A string end to end, so nothing on this path is ever a float.
            'input' => 'string',
            // Not an html5 number input: it insists on a dot, and somebody
            // entering two and a half hours in German types a comma.
            'html5' => false,
            'grouping' => false,
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
     * Read in the language it is being read in — 2,5 for a German reader — where
     * what is stored stays 2.50 whoever is looking.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, self::scaleOf($field));

        return $formatter->format((float) $value) ?: $this->round((float) $value, $field);
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

    /** Cast, or 10 would sort before 9 and "at least 2.5" would compare strings. */
    public function comparableSql(string $accessor): string
    {
        return sprintf('(%s)::numeric', $accessor);
    }

    private function round(float $value, FieldDefinition $field): string
    {
        return number_format($value, self::scaleOf($field), '.', '');
    }

    private static function scaleOf(FieldDefinition $field): int
    {
        $scale = $field->getOption(self::SCALE, self::DEFAULT_SCALE);

        // Between none and six: a field with negative places describes nothing,
        // and one with twenty is asking for a precision the storage does not
        // promise.
        return \is_int($scale) ? max(0, min(6, $scale)) : self::DEFAULT_SCALE;
    }

    /**
     * A measured quantity, the same shape as a count with a fraction on the end.
     */
    public function defaultWidth(): int
    {
        return 3;
    }
}

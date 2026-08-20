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

use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\BoundsItsValues;
use Xivi\Core\Query\Operator;

/**
 * A number with nothing after the point (XIV-22).
 *
 * **Deliberately not thousand-grouped**, which is the one decision on this type
 * and is not an oversight. `currency` and `decimal` group their derived values
 * (XIV-47) because a figure nobody types back is safe to punctuate; this type is
 * the one that must not, because it covers things that are *counted* and things
 * that are merely *written as digits*, and nothing in a definition tells them
 * apart. Grouping turns the year 2026 into `2.026` and the postcode 8001 into
 * `8.001`. Being right about a quantity is not worth being wrong about a year,
 * and the only integer this codebase itself ships is a row reference.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class IntegerFieldType implements BoundsItsValues
{
    public function key(): string
    {
        return 'integer';
    }

    public function label(): string
    {
        return 'Whole number';
    }

    public function constraints(FieldDefinition $field): array
    {
        $constraints = [new Assert\Type('int')];

        $min = $field->getOption('min');
        $max = $field->getOption('max');

        if (\is_int($min) || \is_int($max)) {
            $constraints[] = new Assert\Range(min: $min, max: $max);
        }

        return $constraints;
    }

    public function sample(FieldDefinition $field, int $sequence): ?int
    {
        if (!$field->isRequired() && mt_rand(1, 10) === 1) {
            return null;
        }

        $min = $field->getOption('min');
        $max = $field->getOption('max');

        // Within whatever the field allows, so a generated value never fails the
        // validation this same definition builds.
        return mt_rand(\is_int($min) ? $min : 1, \is_int($max) ? $max : 1000);
    }

    /**
     * `mixed` rather than `?int`, and the narrower one was a bug ([XIV-146]).
     *
     * The comment below has always said that anything which is not a whole
     * number is handed back as it came, and the return type said that could not
     * happen: `"12abc"` went in, `"12abc"` came out, and PHP raised a TypeError
     * before the validator this class is deferring to ever saw it. Nothing
     * reached it, because a form coerces and an importer refuses first, and then
     * XIV-146 sent a whole column of somebody's text through here to find out
     * whether it could be read as numbers. The interface says `mixed` for
     * exactly this reason and this is the type agreeing with it.
     */
    public function toStorage(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Anything that is not a whole number is left alone: rejecting it is the
        // validator's job, and silently casting "12abc" to 12 would store a value
        // the user never entered.
        return \is_int($value) || (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1)
            ? (int) $value
            : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public function formType(): string
    {
        return IntegerType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        $attr = [];

        foreach (['min', 'max'] as $bound) {
            if (\is_int($field->getOption($bound))) {
                $attr[$bound] = $field->getOption($bound);
            }
        }

        return $attr === [] ? [] : ['attr' => $attr];
    }

    public function display(mixed $value, FieldDefinition $field): string
    {
        return \is_int($value) ? (string) $value : '';
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
     * Cast, or 9 would sort after 10 and "at least 5" would be a text
     * comparison. numeric rather than int because it cannot overflow on a value
     * some other writer put there.
     */
    public function comparableSql(string $accessor): string
    {
        return sprintf('(%s)::numeric', $accessor);
    }

    /**
     * A count is a few characters and a label. Given a whole row it reads as a
     * mistake — a quantity of 3 stretched across a screen.
     */
    public function defaultWidth(): int
    {
        return 3;
    }
}

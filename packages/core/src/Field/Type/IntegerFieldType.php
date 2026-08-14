<?php

declare(strict_types=1);

namespace Xivi\Core\Field\Type;

use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Query\Operator;

final class IntegerFieldType implements FieldType
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

    public function toStorage(mixed $value, FieldDefinition $field): ?int
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
}

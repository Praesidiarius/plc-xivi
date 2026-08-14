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

use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Query\Operator;

/**
 * A calendar date with no time and no zone — a birthday is the same day
 * wherever you read it from.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DateFieldType implements FieldType
{
    public const string FORMAT = 'Y-m-d';

    public function key(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return 'Date';
    }

    public function constraints(FieldDefinition $field): array
    {
        // Validated as the stored form: by the time constraints run, toStorage()
        // has already turned whatever was submitted into a string or left it
        // alone for this to reject.
        return [
            new Assert\Type('string'),
            new Assert\Date(),
        ];
    }

    public function toStorage(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::FORMAT);
        }

        // ISO-8601 sorts and compares as text, which is what makes a plain string
        // usable in JSONB without a cast.
        return $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value);

        return $date === false ? null : $date;
    }

    public function formType(): string
    {
        return DateType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        // A single native date input rather than three dropdowns, and the model
        // value is the immutable date this type hands back from storage.
        return ['widget' => 'single_text', 'input' => 'datetime_immutable', 'html5' => true];
    }

    public function display(mixed $value, FieldDefinition $field): string
    {
        return $value instanceof \DateTimeInterface ? $value->format(self::FORMAT) : '';
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
     * No cast: ISO-8601 compares and sorts as text, which is exactly why dates
     * are stored in that format. A ::date cast here would also turn one bad row
     * into a failed query for the whole list.
     */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }
}

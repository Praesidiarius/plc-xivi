<?php

declare(strict_types=1);

namespace Xivi\Core\Field\Type;

use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;

/**
 * A calendar date with no time and no zone — a birthday is the same day
 * wherever you read it from.
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
}

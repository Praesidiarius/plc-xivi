<?php

declare(strict_types=1);

namespace Xivi\Core\Field\Type;

use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;

final class EmailFieldType implements FieldType
{
    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email address';
    }

    public function constraints(FieldDefinition $field): array
    {
        return [
            new Assert\Type('string'),
            new Assert\Email(),
            new Assert\Length(max: 180),
        ];
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        if ($value === null) {
            return null;
        }

        // Lowercased on the way in, so that "unique" means what a person means by
        // it and a filter does not have to know about casing.
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

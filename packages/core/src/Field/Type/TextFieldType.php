<?php

declare(strict_types=1);

namespace Xivi\Core\Field\Type;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;

final class TextFieldType implements FieldType
{
    public const int DEFAULT_MAX_LENGTH = 255;

    public function key(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Text';
    }

    public function constraints(FieldDefinition $field): array
    {
        return [
            new Assert\Type('string'),
            new Assert\Length(max: (int) $field->getOption('max_length', self::DEFAULT_MAX_LENGTH)),
        ];
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        // An empty string and "not filled in" are the same thing to a user, and
        // keeping them distinct in storage only creates two ways to be empty.
        return $value === '' ? null : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function formType(): string
    {
        return TextType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return ['attr' => ['maxlength' => (int) $field->getOption('max_length', self::DEFAULT_MAX_LENGTH)]];
    }

    public function display(mixed $value, FieldDefinition $field): string
    {
        return \is_string($value) ? $value : '';
    }
}

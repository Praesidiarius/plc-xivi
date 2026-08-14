<?php

declare(strict_types=1);

namespace Xivi\Core\Field\Type;

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Query\Operator;

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

    public function formType(): string
    {
        return EmailType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return ['attr' => ['maxlength' => 180]];
    }

    public function display(mixed $value, FieldDefinition $field): string
    {
        return \is_string($value) ? $value : '';
    }

    public function operators(): array
    {
        return [
            Operator::Contains,
            Operator::StartsWith,
            Operator::Equals,
            Operator::NotEquals,
            Operator::IsEmpty,
            Operator::IsNotEmpty,
        ];
    }

    /**
     * Stored lowercased and trimmed, so a plain comparison already means what a
     * person means by it — no LOWER() around the column, which would also throw
     * away any index on it.
     */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }
}

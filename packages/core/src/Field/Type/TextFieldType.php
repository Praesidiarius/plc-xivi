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

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Demo\SampleVocabulary;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Query\Operator;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
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
            // At least 1: a max length of zero is not a shorter field, it is a
            // field nothing can be stored in, and the constraint refuses it.
            new Assert\Length(max: max(1, (int) $field->getOption('max_length', self::DEFAULT_MAX_LENGTH))),
        ];
    }

    /**
     * A word chosen by what the field is called (see SampleVocabulary), so a
     * demo contact reads as a contact rather than as filler.
     */
    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        // A tenth of optional fields left empty. Real data has holes in it, and a
        // list where every column is filled hides how the page looks when they
        // are not.
        if (!$field->isRequired() && mt_rand(1, 10) === 1) {
            return null;
        }

        $key = mb_strtolower($field->getKey());

        // A company is two words and a legal form, not one word, and it is the
        // difference between demo data that reads as a customer list and one
        // that reads as a word list.
        $value = str_contains($key, 'company') || str_contains($key, 'organisation')
            ? SampleVocabulary::company()
            : SampleVocabulary::oneOf(SampleVocabulary::forKey($field->getKey()));

        if (str_contains($key, 'street')) {
            $value .= ' ' . mt_rand(1, 140);
        }

        // Uniqueness cannot be left to a list of thirty words.
        return $field->isUnique() ? $value . ' ' . $sequence : $value;
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

    /** Already text in the payload, so nothing to convert. */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }
}

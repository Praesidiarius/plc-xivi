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

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Query\Operator;

/**
 * One value out of a closed set the customer defines.
 *
 * The options live in the field's own settings, so adding "Partner" beside
 * "Person" and "Company" is a definition change rather than a release — which is
 * the §5 claim applied to a value's domain rather than to its type.
 *
 * It is also what makes variants possible (§5.5): a shape names one choice field
 * as the one that decides which variant a record is, and the variants *are* that
 * field's options. No second list to keep in step.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ChoiceFieldType implements FieldType
{
    /** Stored value => label, in `options['choices']`. */
    public const string CHOICES = 'choices';

    public function key(): string
    {
        return 'choice';
    }

    public function label(): string
    {
        return 'Choice';
    }

    public function constraints(FieldDefinition $field): array
    {
        $choices = array_keys(self::choicesOf($field));

        return [
            new Assert\Type('string'),
            // An empty option list would otherwise reject everything, including
            // the empty value, which is a confusing way to say "misconfigured".
            ...($choices === [] ? [] : [new Assert\Choice(choices: $choices)]),
        ];
    }

    /**
     * One of this field's own options, never anything else.
     *
     * Which is also how a demo module gets a spread of variants for free: the
     * variant field *is* a choice (§5.5), so generating contacts produces both
     * people and companies without the generator knowing either word.
     */
    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        $choices = array_keys(self::choicesOf($field));

        if ($choices === []) {
            return null;
        }

        return (string) $choices[mt_rand(0, \count($choices) - 1)];
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function formType(): string
    {
        return ChoiceType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return [
            // Symfony wants label => value; the definition stores value => label,
            // because the value is the part that ends up in the database and so
            // is the part worth reading first.
            'choices' => array_flip(self::choicesOf($field)),
            'placeholder' => $field->isRequired() ? false : '—',
            'expanded' => false,
        ];
    }

    public function display(mixed $value, FieldDefinition $field): string
    {
        if (!\is_string($value) || $value === '') {
            return '';
        }

        // The label if it is still an option, the raw value if it is not: a
        // record stored under an option since removed still has to render.
        return self::choicesOf($field)[$value] ?? $value;
    }

    public function operators(): array
    {
        return [Operator::Equals, Operator::NotEquals, Operator::IsEmpty, Operator::IsNotEmpty];
    }

    /** Already text in the payload, and compared as the stored value, not the label. */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    /** @return array<string, string> value => label */
    public static function choicesOf(FieldDefinition $field): array
    {
        $choices = $field->getOption(self::CHOICES, []);

        if (!\is_array($choices)) {
            return [];
        }

        $clean = [];
        foreach ($choices as $value => $label) {
            $clean[(string) $value] = \is_scalar($label) ? (string) $label : (string) $value;
        }

        return $clean;
    }
}

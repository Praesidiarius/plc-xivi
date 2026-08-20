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

use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Demo\SampleVocabulary;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\LimitsItsLength;
use Xivi\Core\Query\Operator;

/**
 * Text with room to breathe: a description, a note, an instruction (XIV-11).
 *
 * Its own type rather than an option on `text`, because everything that follows
 * from the length differs — the widget is a box instead of a line, the default
 * maximum is thousands rather than hundreds, and asking whether a description
 * *starts with* something is not a question anybody has.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TextareaFieldType implements LimitsItsLength
{
    /** Long enough for a real description, short enough to stay a field. */
    public const int DEFAULT_MAX_LENGTH = 5000;

    public const int DEFAULT_ROWS = 5;

    public function __construct(private readonly SampleVocabulary $vocabulary)
    {
    }

    public function key(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return 'Long text';
    }

    public function constraints(FieldDefinition $field): array
    {
        return [
            new Assert\Type('string'),
            new Assert\Length(max: $this->maxLength($field)),
        ];
    }

    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        if (!$field->isRequired() && mt_rand(1, 10) === 1) {
            return null;
        }

        // A few phrases rather than one word: a description that is one noun
        // makes a demo look like a form nobody filled in.
        $sentence = implode(' ', array_map(
            fn (): string => $this->vocabulary->forKey($field->getKey()),
            range(1, mt_rand(2, 5)),
        ));

        return mb_substr($sentence, 0, $this->maxLength($field));
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        if ($value === null) {
            return null;
        }

        // Trailing whitespace only, so the paragraph breaks somebody typed are
        // theirs to keep. Empty and "not filled in" stay one thing, as in text.
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function formType(): string
    {
        return TextareaType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return [
            'attr' => [
                'maxlength' => $this->maxLength($field),
                'rows' => $field->getOption('rows') ?? self::DEFAULT_ROWS,
            ],
        ];
    }

    /**
     * The text as typed. Line breaks are not turned into markup here: this
     * returns a value, and a template deciding it is HTML is how stored text
     * becomes a script tag.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        return \is_string($value) ? $value : '';
    }

    public function operators(): array
    {
        return [
            Operator::Contains,
            Operator::IsEmpty,
            Operator::IsNotEmpty,
        ];
    }

    /** Text in the payload already, like `text`. */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    /** @return int<1, max> at least one, since a field nothing fits in is not a shorter field */
    private function maxLength(FieldDefinition $field): int
    {
        return max(1, (int) $field->getOption('max_length', self::DEFAULT_MAX_LENGTH));
    }

    /**
     * The one type whose entire point is room to write. Anything narrower would be
     * a box you cannot see a sentence in.
     */
    public function defaultWidth(): int
    {
        return 12;
    }
}

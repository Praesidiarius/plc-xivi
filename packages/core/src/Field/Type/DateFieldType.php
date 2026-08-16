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

    /**
     * Somewhere in the last forty years, which covers both a birthday and a date
     * something happened, and sorts into a spread rather than a clump.
     */
    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        if (!$field->isRequired() && mt_rand(1, 10) === 1) {
            return null;
        }

        return (new \DateTimeImmutable('today'))
            ->modify(sprintf('-%d days', mt_rand(0, 365 * 40)))
            ->format(self::FORMAT);
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

    /**
     * The date, written the way this reader's country writes one (XIV-50).
     *
     * **`self::FORMAT` is deliberately not used here, and that is the whole
     * point.** It is the *storage* format: dates are kept as ISO strings because
     * they then sort and compare as text, which is what makes a plain string
     * usable in JSONB without a cast (§5). Localizing by reaching for that
     * constant would localize what goes in the database and quietly break every
     * sort and filter — the mistake `CurrencyFieldType` made when one method
     * both formatted and normalized (XIV-47).
     *
     * So: stored the same for everybody, shown the way each person reads. The
     * locale's *short* pattern, because a list column is not a sentence — but
     * with the year widened, since CLDR's short forms mostly write it as two
     * digits and a record that says `15.08.26` is a record somebody has to think
     * about. The order of the fields stays whatever the country uses.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        if (!$value instanceof \DateTimeInterface) {
            return '';
        }

        $formatter = new \IntlDateFormatter(
            \Locale::getDefault(),
            \IntlDateFormatter::SHORT,
            \IntlDateFormatter::NONE,
        );

        $formatter->setPattern(preg_replace('/y+/', 'yyyy', (string) $formatter->getPattern()) ?? '');

        return $formatter->format($value) ?: $value->format(self::FORMAT);
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

    /**
     * A date is a known width in every locale that writes one, and the picker
     * beside it is a fixed size.
     */
    public function defaultWidth(): int
    {
        return 4;
    }
}

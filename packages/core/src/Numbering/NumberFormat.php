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

namespace Xivi\Core\Numbering;

use Xivi\Core\Entity\FieldDefinition;

/**
 * "Number this field from a sequence, and here is what the numbers look like"
 * (XIV-15).
 *
 * **One pattern, not three settings.** The obvious shape was a prefix, a padding
 * width and a "resets each year" switch; a pattern says all three and reads like
 * the thing it produces:
 *
 *     ORD-{year}-{number:4}    →  ORD-2026-0001
 *     {number:6}               →  000042
 *     RE{year}{number:3}       →  RE2026007
 *
 * And it makes the third setting impossible to get wrong, because **the pattern
 * decides the period**: a number containing the year resets each year, and one
 * that does not, does not. Those were never independent — a year in the number
 * that did not reset would look absurd by 2028, and a reset without the year in
 * it would hand out 0001 twice.
 *
 * **Declared as an option, like {@see \Xivi\Core\Record\InheritedValue}**, rather
 * than as a field type. A number is a string; what is special about it is who
 * fills it in, and that is a fact about the field rather than about the kind of
 * value. So it works on any text field, and a customer can change the pattern in
 * the metadata editor without a deployment (§5.4).
 *
 * A pattern with no `{number}` in it is not a sequence. Every record would be
 * called the same thing, and silently numbering nothing is the kinder failure:
 * the field goes on being an ordinary text field somebody can type in.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NumberFormat
{
    public const string OPTION = 'sequence';

    /** `{number}` or `{number:4}` — the counter, optionally padded. */
    private const string COUNTER = '/\{number(?::(\d+))?\}/';

    /** `{year}` — four digits, and the thing that makes the sequence reset. */
    private const string YEAR = '{year}';

    private function __construct(public string $pattern)
    {
    }

    /**
     * As a field's options, for a blueprint to spread into its own.
     *
     * @return array{sequence: string}
     */
    public static function from(string $pattern): array
    {
        return [self::OPTION => $pattern];
    }

    /** How this field is numbered, or null when it is not. */
    public static function of(FieldDefinition $field): ?self
    {
        $pattern = $field->getOption(self::OPTION);

        if (!\is_string($pattern) || preg_match(self::COUNTER, $pattern) !== 1) {
            return null;
        }

        return new self($pattern);
    }

    /**
     * Which counter a number allocated on that day comes out of: the year, or
     * the empty string for a sequence that runs forever.
     *
     * The day the number is *allocated*, deliberately, rather than a date on the
     * record. Somebody backdating an order to December must not be able to reach
     * into last year's numbering, which is a book that is closed.
     */
    public function period(\DateTimeImmutable $on): string
    {
        return str_contains($this->pattern, self::YEAR) ? $on->format('Y') : '';
    }

    /** What the number looks like once it is the record's. */
    public function render(int $value, \DateTimeImmutable $on): string
    {
        return (string) preg_replace_callback(
            self::COUNTER,
            // `{number}` with no width leaves no capture group at all, which is
            // an absent key rather than an empty one.
            static fn (array $match): string => ($match[1] ?? '') === ''
                ? (string) $value
                : str_pad((string) $value, (int) $match[1], '0', \STR_PAD_LEFT),
            str_replace(self::YEAR, $on->format('Y'), $this->pattern),
        );
    }
}

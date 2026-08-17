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
 * the metadata editor without a deployment (§5.4, XIV-27).
 *
 * A pattern with no `{number}` in it is not a sequence. Every record would be
 * called the same thing, and silently numbering nothing is the kinder failure
 * *for a pattern that arrives from a blueprint*: the field goes on being an
 * ordinary text field somebody can type in. It is the wrong answer for one
 * somebody has just typed into a form, which is why the editor refuses that
 * instead of storing it ({@see \Xivi\Core\Metadata\MetadataEditor}) — a customer
 * who meant to set up numbering and got silence would have no way of telling
 * that from success. Same rule, two audiences, and the difference is only who
 * can still be told.
 *
 * **Everything here is static analysis of the pattern text**, which is the
 * property XIV-27 turned into a promise to the reader. {@see of()} decides
 * whether this field is numbered at all by looking for `{number}`, and
 * {@see period()} decides *which counter* a number comes out of by looking for
 * `{year}` — so a page can say both before anything is saved, from what has been
 * typed so far. Symfony's ExpressionLanguage was proposed for the syntax and
 * rejected on exactly this point: an evaluator can only answer by running, and
 * `'ORD-' ~ (annual ? year : '')` has no static answer at all. The full argument
 * is on XIV-27; what it leaves behind here is a rule — the two regexes below stay
 * regexes.
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

        return \is_string($pattern) ? self::parse($pattern) : null;
    }

    /**
     * The same question about a pattern nobody has stored yet (XIV-27).
     *
     * The editor's preview asks this on every keystroke, against text that is
     * half-typed most of the time, so it answers with null rather than throwing:
     * `ORD-{numb` is somebody mid-word, not an error, and a page that raised on
     * the way to a valid pattern would be unusable. What to *do* about a null is
     * the caller's — the preview says "this would number nothing" and the editor
     * refuses to save it.
     */
    public static function parse(string $pattern): ?self
    {
        return preg_match(self::COUNTER, $pattern) === 1 ? new self($pattern) : null;
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
        return $this->resetsAnnually() ? $on->format('Y') : '';
    }

    /**
     * Whether the counter behind this starts again each January.
     *
     * The same fact {@see period()} reads, asked as the question a person asks —
     * and it is the sentence the editor puts on screen, because "the counter for
     * 2026" and "one counter, always" are the two things a customer is choosing
     * between when they add or remove `{year}` without necessarily realising it
     * (XIV-27). A template comparing `period()` against the empty string would be
     * the same knowledge, written where nobody would look for it.
     */
    public function resetsAnnually(): bool
    {
        return str_contains($this->pattern, self::YEAR);
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

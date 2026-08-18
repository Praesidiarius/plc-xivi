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

    /**
     * The literal text every number of this pattern begins with, on a given day
     * (XIV-91).
     *
     * Only useful for one thing, and it is worth saying which: narrowing the
     * scan of a column somebody has been typing into by hand. Turning numbering
     * on for a populated field means finding out whether the counter is about to
     * hand out a number a record already carries, and that question is answered
     * against the *column* rather than against the counter — so something has to
     * read the column. `ORD-2026-%` in a `LIKE` throws away the rows that cannot
     * possibly be an answer before they reach PHP, which on a text field a
     * customer has been filling in for three years is most of them.
     *
     * It is a narrowing and never the test. What decides whether a value is one
     * of ours is {@see counterIn()}, on the values that survive this — a prefix
     * cannot tell `ORD-2026-0007` from `ORD-2026-draft`, and it is not asked to.
     *
     * The empty string for a pattern that starts with its counter, which is the
     * honest answer: `{number:6}` narrows nothing, because every value in the
     * column might be one of its numbers.
     */
    public function literalPrefix(\DateTimeImmutable $on): string
    {
        $literal = str_replace(self::YEAR, $on->format('Y'), $this->pattern);
        $parts = preg_split(self::COUNTER, $literal, 2);

        return \is_array($parts) ? $parts[0] : '';
    }

    /**
     * The counter value a piece of text would have come out of, or null (XIV-91).
     *
     * {@see render()} read backwards, and the reason it exists is the one
     * duplicate this feature could otherwise not see. A text field being made
     * numbered may already hold `RE-2026-0007`, typed by a person; a counter
     * starting at 1 knows nothing about it and would eventually render exactly
     * that string onto a second record. The counter's own guard cannot help —
     * it compares against the counter, and the collision is in the column — so
     * the column is read, every value that *this pattern could have produced* is
     * recognised, and the counter is floored above the highest of them.
     *
     * **Recognition is the pattern's own arithmetic, not a heuristic.** A value
     * is one of ours when the literals line up exactly and the holes are digits;
     * anything else — `Referenz 12`, `RE-2026-draft`, last year's
     * `RE-2025-0007` under a `{year}` pattern — is not something this pattern
     * will ever render, so it cannot be duplicated by the counter and is left
     * alone. That is the whole answer to "and then what about `Referenz 12`?":
     * nothing, by construction, because the numbers we hand out never look like
     * it.
     *
     * The day matters for the same reason it matters everywhere else here: under
     * a `{year}` pattern each year is a counter of its own, so only the values
     * belonging to *this* year's counter can be duplicated by it.
     *
     * Digits beyond what an int can hold are refused rather than truncated. A
     * value like `RE-99999999999999999999` is not a number this counter will
     * reach, and silently rounding it to PHP_INT_MAX would floor the counter at
     * a number nobody asked for.
     */
    public function counterIn(string $value, \DateTimeImmutable $on): ?int
    {
        $literal = str_replace(self::YEAR, $on->format('Y'), $this->pattern);
        $around = preg_split(self::COUNTER, $literal);

        if (!\is_array($around)) {
            return null;
        }

        $expression = '/^' . implode('(\d+)', array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            $around,
        )) . '$/';

        if (preg_match($expression, $value, $matches) !== 1) {
            return null;
        }

        $found = null;

        // A pattern may name `{number}` twice, and render() writes the same
        // value into both holes. So a text with two different numbers in it was
        // not produced here, however well the literals line up.
        foreach (\array_slice($matches, 1) as $digits) {
            $trimmed = ltrim($digits, '0');
            $trimmed = $trimmed === '' ? '0' : $trimmed;

            if ((string) (int) $trimmed !== $trimmed) {
                return null;
            }

            if ($found !== null && (int) $trimmed !== $found) {
                return null;
            }

            $found = (int) $trimmed;
        }

        return $found;
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

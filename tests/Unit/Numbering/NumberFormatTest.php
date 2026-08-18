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

namespace App\Tests\Unit\Numbering;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Numbering\NumberFormat;

/**
 * What a document number looks like, and which counter it comes out of (XIV-15).
 *
 * A unit test because the year is in it: whether a sequence resets is decided by
 * reading the pattern, and the only honest way to check "and in January it
 * starts again" is to hand it a date in January.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NumberFormatTest extends TestCase
{
    public function testAPatternIsFilledInWithThePaddedCounter(): void
    {
        self::assertSame('ORD-2026-0001', $this->render('ORD-{year}-{number:4}', 1, '2026-08-15'));
        self::assertSame('ORD-2026-0042', $this->render('ORD-{year}-{number:4}', 42, '2026-08-15'));
        self::assertSame('RE2026007', $this->render('RE{year}{number:3}', 7, '2026-12-31'));
    }

    /** A counter wider than its padding is not truncated: it grows. */
    public function testANumberWiderThanItsPaddingKeepsAllOfItself(): void
    {
        self::assertSame('ORD-2026-12345', $this->render('ORD-{year}-{number:4}', 12345, '2026-08-15'));
    }

    public function testPaddingIsOptional(): void
    {
        self::assertSame('42', $this->render('{number}', 42, '2026-08-15'));
    }

    /**
     * The pattern decides the period, which is the point of it being a pattern:
     * a year in the number resets each year, and one without does not.
     */
    public function testWhetherTheYearIsInTheNumberIsWhetherItResets(): void
    {
        $yearly = $this->format('ORD-{year}-{number:4}');
        $forever = $this->format('ORD-{number:6}');

        self::assertNotNull($yearly);
        self::assertNotNull($forever);

        self::assertSame('2026', $yearly->period(new \DateTimeImmutable('2026-12-31')));
        self::assertSame('2027', $yearly->period(new \DateTimeImmutable('2027-01-01')), 'a different counter');
        self::assertSame('', $forever->period(new \DateTimeImmutable('2027-01-01')), 'and this one never changes');
    }

    /**
     * A pattern with no counter in it is not a sequence. Numbering every record
     * "INVOICE" is worse than leaving the field to be typed in.
     */
    public function testAPatternWithNoCounterIsNotASequence(): void
    {
        self::assertNull($this->format('INVOICE-{year}'));
        self::assertNull($this->format(''));
    }

    public function testAFieldWithNoPatternIsNotNumbered(): void
    {
        self::assertNull(NumberFormat::of($this->field([])));
        self::assertNull(NumberFormat::of($this->field([NumberFormat::OPTION => ['not' => 'a string']])));
    }

    /**
     * The same question about text nobody has stored yet (XIV-27).
     *
     * This is what the metadata editor's preview asks on every keystroke, so the
     * half-typed cases are the ones that matter: they answer null rather than
     * throwing, because `ORD-{numb` is somebody mid-word on the way to a pattern
     * that works.
     */
    public function testAPatternCanBeReadBeforeItIsStored(): void
    {
        self::assertNotNull(NumberFormat::parse('ORD-{year}-{number:4}'));
        self::assertNull(NumberFormat::parse('ORD-{numb'), 'mid-word');
        self::assertNull(NumberFormat::parse('INVOICE'), 'and a pattern that would name every record the same');
        self::assertNull(NumberFormat::parse(''), 'and an emptied box');
    }

    /**
     * Whether the counter restarts, asked as the question a person asks.
     *
     * The editor puts this on screen in words before anything is saved — "the
     * counter for 2026" against "one counter, always" — which is the whole
     * reason the pattern is read statically rather than evaluated.
     */
    public function testWhetherASequenceRestartsIsReadableFromThePatternAlone(): void
    {
        self::assertTrue(NumberFormat::parse('ORD-{year}-{number:4}')?->resetsAnnually());
        self::assertFalse(NumberFormat::parse('ORD-{number:6}')?->resetsAnnually());
    }

    /**
     * Reading a number back out of a value somebody typed (XIV-91).
     *
     * {@see NumberFormat::render()} run backwards, and the reason it exists is
     * the one duplicate the counter's own guard structurally cannot see: a text
     * field being made numbered may already hold `RE-2026-0007`, which no
     * counter ever gave out. Recognising it is what lets the counter be floored
     * above it.
     *
     * The negative cases are the load-bearing ones. Everything this does *not*
     * recognise is by construction something the pattern could never render, so
     * the counter cannot duplicate it and nothing needs to be done about it —
     * which is the whole answer to "and then what about `Referenz 12`?".
     */
    public function testAValueCanBeRecognisedAsSomethingThisPatternWouldProduce(): void
    {
        $annual = $this->format('RE-{year}-{number:4}');
        self::assertNotNull($annual);
        $on = new \DateTimeImmutable('2026-03-01');

        self::assertSame(7, $annual->counterIn('RE-2026-0007', $on), 'padding is not part of the value');
        self::assertSame(1043, $annual->counterIn('RE-2026-1043', $on), 'nor is a number wider than the padding');
        self::assertNull($annual->counterIn('RE-2025-0007', $on), "last year's counter is a different counter");
        self::assertNull($annual->counterIn('Referenz 12', $on), 'something a person wrote');
        self::assertNull($annual->counterIn('RE-2026-draft', $on), 'the literals line up and the hole is not digits');
        self::assertNull($annual->counterIn('XRE-2026-0007', $on), 'and it is anchored at both ends');
        self::assertNull(
            $annual->counterIn('RE-2026-99999999999999999999', $on),
            'more digits than an int can hold is refused rather than truncated',
        );

        // A counter that never restarts reads the same way, minus the year.
        $forever = $this->format('{number:6}');
        self::assertNotNull($forever);
        self::assertSame(42, $forever->counterIn('000042', $on));
        self::assertNull($forever->counterIn('42a', $on));
    }

    /**
     * The text every number of a pattern begins with, which is only ever a
     * narrowing (XIV-91).
     *
     * It exists so that a scan of a column somebody has been typing into for
     * three years throws away the rows that cannot be an answer before they
     * reach PHP. It is never the test — `counterIn()` above is — and the empty
     * string for a pattern that starts with its counter is the honest answer
     * rather than a case to guard against.
     */
    public function testThePrefixNarrowsTheScanAndDecidesNothing(): void
    {
        $on = new \DateTimeImmutable('2026-03-01');

        self::assertSame('RE-2026-', $this->format('RE-{year}-{number:4}')?->literalPrefix($on));
        self::assertSame('', $this->format('{number:6}')?->literalPrefix($on), 'nothing to narrow by');
    }

    // -- helpers ------------------------------------------------------------

    private function render(string $pattern, int $value, string $on): string
    {
        $format = $this->format($pattern);
        self::assertNotNull($format);

        return $format->render($value, new \DateTimeImmutable($on));
    }

    private function format(string $pattern): ?NumberFormat
    {
        return NumberFormat::of($this->field(NumberFormat::from($pattern)));
    }

    /** @param array<string, mixed> $options */
    private function field(array $options): FieldDefinition
    {
        $field = new FieldDefinition(
            new ModuleDefinition('order', 'Orders', 'sales_order'),
            'number',
            'Number',
            'text',
        );
        $field->setOptions($options);

        return $field;
    }
}

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

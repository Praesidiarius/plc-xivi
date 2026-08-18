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

namespace App\Tests\Unit\Document;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Document\DocumentImages;

/**
 * How large a logo comes out on the page (XIV-89).
 *
 * **The rule is a decision rather than an implementation detail**, which is why
 * it has a test of its own rather than being asserted in passing by the
 * functional one. §5.7 states it — natural size at 96 dpi, scaled down to fit
 * 40 × 20 mm, never scaled up — and a decision written in prose and nowhere else
 * is one that quietly becomes something different the first time somebody
 * adjusts a constant to make a document look better on their screen. These
 * numbers are what the brief claims, in EMU.
 *
 * A unit test because the arithmetic needs no kernel, no database and no Word:
 * it costs a millisecond and says exactly what changed when it goes red.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class LogoExtentTest extends TestCase
{
    /** An inch, by definition, and the unit everything below is expressed in. */
    private const int EMU_PER_INCH = 914400;

    /** What the brief promises as the widest a mark is drawn. */
    private const int WIDEST = 40 * 36000;

    /** And the tallest. */
    private const int TALLEST = 20 * 36000;

    /**
     * A wordmark at a sensible resolution is drawn at the size it is.
     *
     * 96 pixels is an inch, so a 192 × 48 image is two inches by half of one —
     * about 51 × 13 mm, which is over the width of the box and therefore not
     * this case. 120 × 40 is: 31.75 × 10.58 mm, inside both limits, drawn
     * untouched. Stated in inches rather than in EMU so that "natural size at 96
     * dpi" is legible as arithmetic rather than as a magic number.
     */
    public function testAnImageSmallerThanTheBoxIsDrawnAtItsNaturalSize(): void
    {
        [$cx, $cy] = DocumentImages::extentOf(120, 40);

        self::assertSame((int) round(self::EMU_PER_INCH * 120 / 96), $cx);
        self::assertSame((int) round(self::EMU_PER_INCH * 40 / 96), $cy);
        self::assertLessThan(self::WIDEST, $cx);
        self::assertLessThan(self::TALLEST, $cy);
    }

    /**
     * The case that made a box necessary at all.
     *
     * Logos are exported at two or three times their intended size as a matter
     * of course, so a 1200-pixel-wide PNG is not somebody asking for a banner
     * twelve inches across — it is a 40 mm wordmark at 3×. Without the ceiling
     * this would be 317 mm wide on a page that is 210 mm.
     */
    public function testAWideMarkIsScaledDownToTheWidthOfTheBox(): void
    {
        [$cx, $cy] = DocumentImages::extentOf(1200, 400);

        self::assertSame(self::WIDEST, $cx);
        // A third as tall as it is wide, exactly as it went in: the box bounds
        // the picture, it does not reshape it.
        self::assertSame((int) round(self::WIDEST / 3), $cy);
    }

    /**
     * A square mark hits the height first, which is the whole reason there are
     * two limits rather than one.
     *
     * A single width limit would draw a square logo 40 mm tall, which is a fifth
     * of the page and reads as a mistake.
     */
    public function testASquareMarkIsBoundedByTheHeightInstead(): void
    {
        self::assertSame([self::TALLEST, self::TALLEST], DocumentImages::extentOf(2000, 2000));
    }

    /**
     * Nothing is ever enlarged.
     *
     * Blowing a small bitmap up to fill the box is how a crisp mark acquires
     * soft edges, and the customer has no way of knowing we did it — the same
     * argument §8.6 makes for not re-encoding the upload.
     */
    public function testASmallMarkIsNotBlownUpToFillTheBox(): void
    {
        [$cx, $cy] = DocumentImages::extentOf(20, 10);

        self::assertSame(20 * 9525, $cx);
        self::assertSame(10 * 9525, $cy);
        self::assertLessThan(self::WIDEST, $cx);
    }

    /**
     * And an extent is never zero.
     *
     * A drawing of no size is one Word draws as nothing at all, and rounding is
     * the only route to it — so the floor is stated rather than assumed.
     */
    public function testAnExtentIsNeverZero(): void
    {
        [$cx, $cy] = DocumentImages::extentOf(1, 1);

        self::assertGreaterThan(0, $cx);
        self::assertGreaterThan(0, $cy);
    }
}

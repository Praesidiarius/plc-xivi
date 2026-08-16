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

namespace App\Tests\Unit;

use App\Twig\Avatar;
use PHPUnit\Framework\TestCase;

/**
 * The initials and the colour, which is all there is to an avatar here (XIV-77).
 *
 * A unit test because there is nothing to boot: the whole point of generating
 * the thing rather than fetching it is that it is a pure function of two strings.
 * What is worth pinning down is the edges — an account with no name, a name that
 * is one word, an email that has no punctuation to split on — because each of
 * those has to draw as *something* and a blank circle is the failure that looks
 * like a styling bug.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AvatarTest extends TestCase
{
    public function testTwoWordsGiveTwoInitials(): void
    {
        self::assertSame('AL', Avatar::for('Ada Lovelace', 'ada@example.test')->initials);
    }

    /** The first and the last, not the first two: a middle name is not the point. */
    public function testThreeWordsGiveTheFirstAndTheLast(): void
    {
        self::assertSame('AV', Avatar::for('Anna Maria Vogt', 'anna@example.test')->initials);
    }

    public function testOneWordGivesOneInitial(): void
    {
        self::assertSame('P', Avatar::for('Prince', 'p@example.test')->initials);
    }

    /** Accents and non-Latin letters are letters. */
    public function testItIsNotByteBased(): void
    {
        self::assertSame('ÉÖ', Avatar::for('Éloïse Österreicher', 'e@example.test')->initials);
    }

    /**
     * A name that was never filled in falls back to the email's local part, split
     * the same way — otherwise everybody at one company would be a single letter.
     */
    public function testAnEmptyNameFallsBackToTheEmail(): void
    {
        self::assertSame('AM', Avatar::for('', 'anna.mueller@example.test')->initials);
        self::assertSame('AM', Avatar::for('   ', 'anna_mueller@example.test')->initials);
        self::assertSame('A', Avatar::for('', 'admin@example.test')->initials);
    }

    /** Punctuation is skipped rather than drawn. */
    public function testSomethingWithNoLettersInItStillDrawsAsSomething(): void
    {
        self::assertSame('?', Avatar::for('!!!', '!!!')->initials);
    }

    public function testTheHueIsInRangeAndAlwaysTheSame(): void
    {
        $first = Avatar::for('Ada Lovelace', 'ada@example.test');
        $again = Avatar::for('Ada Lovelace', 'ada@example.test');

        self::assertGreaterThanOrEqual(0, $first->hue);
        self::assertLessThan(360, $first->hue);
        self::assertSame($first->hue, $again->hue);
    }

    /**
     * The colour comes from the email, which is why it is the email.
     *
     * Two colleagues with the same name are exactly the case an avatar has to
     * tell apart, and a colour keyed on the name would give them the same circle
     * with the same letters in it.
     */
    public function testTwoPeopleWithTheSameNameGetDifferentColours(): void
    {
        self::assertNotSame(
            Avatar::for('Anna Meier', 'anna.meier@example.test')->hue,
            Avatar::for('Anna Meier', 'a.meier@example.test')->hue,
        );
    }

    /** Nobody's login is case-sensitive, so nobody's colour is either. */
    public function testTheColourIgnoresTheCaseOfTheEmail(): void
    {
        self::assertSame(
            Avatar::for('Ada Lovelace', 'ada@example.test')->hue,
            Avatar::for('Ada Lovelace', 'Ada@Example.test')->hue,
        );
    }
}

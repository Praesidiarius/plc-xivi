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

use PHPUnit\Framework\TestCase;

/**
 * How Switzerland writes a figure in each of its languages (XIV-153).
 *
 * §8.4.2 splits language from region: `FormattingLocale` composes `fr` and `CH`
 * into `fr_CH` and hands it to ICU, and no formatter of ours learns anything
 * about regions. That is exactly why this test exists: when French and Italian
 * joined `enabled_locales`, the region half was *assumed* to come out right,
 * and for French the obvious assumption is wrong. Swiss German and Swiss
 * Italian group with the apostrophe (`1’234’500.00`); Swiss French does not.
 * It groups with a narrow no-break space and writes a decimal *comma*, except
 * in money, where CLDR gives fr_CH a currency-specific decimal *point*. Three
 * languages, one country, and three different answers, none of which any code
 * of ours chooses: these assertions pin what ICU does, so an ICU upgrade that
 * moves a separator fails loudly here instead of quietly on an invoice.
 *
 * The de_CH figures are asserted where the region split was built, in
 * OrderTotalsTest::testTheSameLanguageWritesDifferentlyInDifferentCountries;
 * this file covers the two languages XIV-153 added. A unit test because ICU
 * needs no kernel and no database, so it costs milliseconds and runs first.
 *
 * The separators below are real characters, not ASCII lookalikes: U+2019 for
 * the apostrophe, U+202F (narrow no-break space) inside French numbers, and
 * U+00A0 between amount and currency code.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SwissFiguresTest extends TestCase
{
    /**
     * The same number every catalogue's comments quote, in both new languages.
     *
     * DecimalFieldType formats through exactly this: a DECIMAL NumberFormatter
     * on the request locale, fraction digits from the field.
     */
    public function testSwissFrenchAndSwissItalianWriteAPlainNumberDifferently(): void
    {
        self::assertSame("1\u{202F}234\u{202F}500,00", self::decimal('fr_CH'), 'Swiss French: narrow spaces, and a decimal comma');
        self::assertSame("1\u{2019}234\u{2019}500.00", self::decimal('it_CH'), 'Swiss Italian: apostrophes, and a decimal point');
    }

    /**
     * Money is the interesting one: fr_CH switches its decimal separator.
     *
     * A price on a French-language Swiss invoice is written `1 234.50 CHF`:
     * grouping like the language, decimal point like the country's banking
     * convention. CLDR carries that as a currency-specific decimal symbol, and
     * CurrencyFieldType gets it for free by asking for a CURRENCY formatter.
     * If this ever fails, the page and the printed invoice disagree with what
     * a Swiss reader expects at the till, which is worth a red build.
     */
    public function testSwissFrenchMoneyKeepsTheDecimalPoint(): void
    {
        self::assertSame("1\u{202F}234.50\u{00A0}CHF", self::currency('fr_CH'));
    }

    /** Swiss Italian money reads exactly like Swiss German money. */
    public function testSwissItalianMoneyMatchesTheGermanConvention(): void
    {
        self::assertSame("CHF\u{00A0}1\u{2019}234.50", self::currency('it_CH'));
        self::assertSame(self::currency('de_CH'), self::currency('it_CH'));
    }

    /**
     * Dates: all three Swiss languages share the dotted short form.
     *
     * DateFieldType renders with IntlDateFormatter::SHORT, so this is the shape
     * a date column actually takes. France writes 19/08/2026; Swiss French
     * writes 19.08.26 like its German- and Italian-speaking neighbours, and a
     * build where the three languages of one company disagreed about the date
     * column would look broken in a way no error message ever reports.
     */
    public function testAllThreeSwissLanguagesShareTheShortDateShape(): void
    {
        foreach (['de_CH', 'fr_CH', 'it_CH'] as $locale) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::SHORT, \IntlDateFormatter::NONE, 'UTC');

            self::assertSame('19.08.26', $formatter->format(new \DateTimeImmutable('2026-08-19', new \DateTimeZone('UTC'))), $locale);
        }
    }

    private static function decimal(string $locale): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);

        $formatted = $formatter->format(1234500);
        self::assertNotFalse($formatted);

        return $formatted;
    }

    private static function currency(string $locale): string
    {
        $formatted = new \NumberFormatter($locale, \NumberFormatter::CURRENCY)->formatCurrency(1234.5, 'CHF');
        self::assertNotFalse($formatted);

        return $formatted;
    }
}

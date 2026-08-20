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

namespace App\Tests\Support;

use Symfony\Component\Intl\Countries;

/**
 * The locales a test has to try before it may claim a value survives being read
 * (XIV-45).
 *
 * **The problem this exists to solve.** §8.4.2 stores a language and a region
 * separately and composes them, so what a formatter is actually handed is not
 * one of the four entries in `enabled_locales` but one of those four times a
 * country: `de` and `CH` make `de_CH`. Testing the four bare languages would
 * therefore miss the finding XIV-153 landed with, which is that the region half
 * changes the answer and changes it inconsistently: `fr` writes a plain number
 * with a decimal comma and Swiss French writes money with a decimal point, so a
 * suite that had checked `fr` would have learned nothing about `fr_CH`. And
 * testing every country is 249 of them per language, which is a suite that
 * multiplies by the wrong thing.
 *
 * **So the set is derived rather than listed, by collapsing what cannot
 * differ.** Two locales that write the sample number, the sample amount of
 * money and the sample date identically cannot round-trip differently either,
 * because those three strings *are* everything a formatter of ours varies: the
 * decimal separator, the grouping separator, the digits themselves, the
 * currency's own decimal separator, which side the code goes on, and the order
 * and separators of a date's fields. Keeping one locale per distinct triple
 * covers all 249 countries at the cost of a handful. Today that is thirty
 * locales for four languages, and the interesting ones fall out of it without
 * anybody having noticed they were interesting: `en_IN`, which groups in lakhs;
 * `fr_CH`, which disagrees with `fr` about the decimal comma; `de_CH`, which
 * disagrees with `de` about everything.
 *
 * **Nothing here is hand-listed, including the languages.** They come from
 * `enabled_locales`, which is the promise a language in the picker is served
 * whole (`TranslationCatalogueTest` guards the other half of it). Adding a
 * fifth language adds its own five or six formatting locales here and needs no
 * edit, which is the property XIV-45 was asked for: a language cannot arrive
 * and leave the round trips behind.
 *
 * The cost of one more language is therefore *its* distinct formats, not four
 * more of everything: German contributes four, Italian three, English
 * seventeen. A language is worth roughly a handful, and every one of them is a
 * few form builds.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FormattingLocales
{
    /**
     * A number with a fraction and enough digits to group, the same one every
     * translation catalogue's comments quote.
     */
    private const float NUMBER = 1234500.5;

    /** Money, which is the one fr_CH writes differently from its own plain numbers. */
    private const float AMOUNT = 1234.5;

    /** The currency the sample amount is written in; any code would do. */
    private const string CURRENCY = 'CHF';

    /**
     * A date whose day and month cannot be confused for one another, so that a
     * locale writing `03.04.2026` and one writing `04/03/2026` are told apart by
     * the signature rather than collapsed into it.
     */
    private const string DATE = '2026-03-04';

    /**
     * Every language, expanded to one locale per way of writing a figure.
     *
     * The bare language is always in the result and always first: it is what
     * `FormattingLocale` hands back for an installation that has never chosen a
     * region, which is every installation until somebody visits the settings
     * page. It also wins the signature it shares, so the set reads as `de`
     * rather than as `de_DE` for the common case.
     *
     * @param list<string> $languages from `%kernel.enabled_locales%`
     *
     * @return list<string>
     */
    public static function of(array $languages): array
    {
        $locales = [];

        foreach ($languages as $language) {
            $seen = [];

            foreach ([null, ...Countries::getCountryCodes()] as $region) {
                $locale = $region === null ? $language : $language . '_' . $region;
                $signature = self::signatureOf($locale);

                if (isset($seen[$signature])) {
                    continue;
                }

                $seen[$signature] = true;
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    /**
     * Everything about a locale that a value crossing between storage and the
     * screen can trip over, as one string.
     *
     * Three renderings rather than a list of separator symbols, because a symbol
     * list is a claim about what ICU varies and these are ICU varying it. A
     * locale that writes its digits in another script, or puts the currency code
     * on the other side, or orders a date differently, differs here without this
     * class having had to know that it could.
     */
    private static function signatureOf(string $locale): string
    {
        $decimal = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $decimal->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);

        $money = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

        $date = new \IntlDateFormatter($locale, \IntlDateFormatter::SHORT, \IntlDateFormatter::NONE, 'UTC');

        return implode('|', [
            $decimal->format(self::NUMBER),
            $money->formatCurrency(self::AMOUNT, self::CURRENCY),
            $date->format(new \DateTimeImmutable(self::DATE, new \DateTimeZone('UTC'))),
        ]);
    }
}

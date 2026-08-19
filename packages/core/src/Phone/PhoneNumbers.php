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

namespace Xivi\Core\Phone;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Region\InstanceRegion;

/**
 * Everything this project knows about phone numbers, which is everything Google
 * knows and nothing of our own (XIV-114).
 *
 * One number has many spellings — `+41 79 123 45 67`, `0791234567`,
 * `079 123 45 67` — and until this existed all three were three different values
 * in a `text` field: a search found one of them, a duplicate check found none of
 * them, and an export was whatever each person had happened to type. This turns
 * any of them into the one form that is unambiguous everywhere, **E.164**
 * (`+41791234567`), and refuses what it cannot.
 *
 * ### The dependency: the lite build, measured rather than assumed
 *
 * `giggsey/libphonenumber-for-php-lite`, and this is the file to argue with if
 * somebody later reaches for the full `giggsey/libphonenumber-for-php`.
 *
 * Both are Apache-2.0 and both are the same port of Google's library; the
 * difference is the data that rides along. Installed into a clean `composer:2`
 * container and measured:
 *
 * | | full | lite |
 * | --- | --- | --- |
 * | Installed `vendor/` | 25 MB | 2.8 MB |
 * | Extra runtime dependency | `giggsey/locale` (3 MB) | none beyond mbstring |
 *
 * The 22 MB buys geocoding ("which city is this number in"), carrier lookup,
 * short-number information and a number-to-timezone mapping. **This file uses
 * none of them.** Everything below is core `PhoneNumberUtil`: parse, validate,
 * format, and one example number for a placeholder. [XIV-96] took the production
 * image from 7.3 GB to 462 MB, so the full build would be 5% of that image spent
 * on metadata about mobile carriers, against 0.6% for the lite one — for a
 * feature that never asks who the carrier is.
 *
 * So the rule to hold: **the day something here wants a city name, a carrier or
 * a zone from a number, that is the day to weigh the full package again** — and
 * to weigh it against the image, out loud, rather than swapping the requirement
 * and moving on. Until then the ceiling is what this class does, which is why
 * the surface it exposes is four methods rather than a passthrough to
 * `PhoneNumberUtil`.
 *
 * ### Validity is data, and data moves
 *
 * `isValidNumber()` is a question about Google's metadata, not about arithmetic:
 * it knows that Swiss mobiles are `07[5-9]` and nine digits long because a table
 * in the package says so. Countries open ranges and retire them, so **a
 * `composer update` can change whether a number is valid** — one that was
 * refused last year may be accepted this year, and, more awkwardly, a number
 * stored years ago can stop being one this build would accept today. Nothing
 * revalidates on read, deliberately: a stored number is a fact about a customer
 * (§5.9), and a library update is not a reason to start refusing to display
 * somebody's phone number. It is a reason for an import to behave differently
 * across two releases, which is written down in §5.23 rather than left to be
 * discovered.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PhoneNumbers
{
    /**
     * The canonical form, and the one thing stored.
     *
     * E.164 because it is the only spelling that is the same everywhere: a
     * leading `+`, the country code, the national number, no separators and no
     * national trunk prefix. Two people typing the same number in two countries
     * produce the same string, which is what makes `unique` mean what a person
     * means by it and what makes a filter box find what somebody is looking for.
     */
    private const PhoneNumberFormat STORED = PhoneNumberFormat::E164;

    private readonly PhoneNumberUtil $numbers;

    public function __construct(private readonly InstanceRegion $instance)
    {
        // A singleton by the library's own design: it parses and caches Google's
        // metadata on first use, so constructing one per call would reload it.
        $this->numbers = PhoneNumberUtil::getInstance();
    }

    /**
     * Which country a value in this field is read against.
     *
     * The field's own answer first, the installation's second, and nothing
     * third. **The field's is an option with a default, not a setting somebody
     * has to fill in** — a customer whose suppliers are all in Germany can say so
     * on that one field, and every other phone field in the tenant goes on
     * following the profile without anybody opening the metadata editor. See
     * {@see PhoneRegion} for why it is an option rather than a field type of its
     * own.
     */
    public function regionFor(FieldDefinition $field): ?string
    {
        return PhoneRegion::of($field) ?? $this->instance->region();
    }

    /**
     * One string, read as a number against one country.
     *
     * The whole of this class's judgement is in the order of the four refusals
     * below, and each of them is a different sentence somebody has to act on.
     *
     * **`isValidNumber()` rather than "did it parse".** Parsing is generous by
     * design — `079 123 45` parses perfectly well and comes back as
     * `+4107912345`, a number that is nine keystrokes and no telephone. Storing
     * it would put something in the column that looks exactly like a phone
     * number and cannot be rung, which is the failure this whole ticket is
     * about, one level down.
     */
    public function read(mixed $value, ?string $region): PhoneReading
    {
        if (!\is_string($value)) {
            return PhoneReading::refused(PhoneProblem::NotANumber);
        }

        $value = trim($value);

        // A value carrying its own country code needs no country to be read
        // against, which is why this is asked before the region is missed: an
        // installation that has never filled in its profile can still keep every
        // number somebody spells out in full.
        if ($region === null && !str_starts_with($value, '+')) {
            return PhoneReading::refused(PhoneProblem::NoCountry);
        }

        try {
            $number = $this->numbers->parse($value, $region);
        } catch (NumberParseException) {
            return PhoneReading::refused(PhoneProblem::NotANumber);
        }

        // Before validity, because a switchboard number with an extension is
        // perfectly valid and is still not something this field can keep — and
        // "not diallable" would be a lie about it. See PhoneProblem.
        if (($number->getExtension() ?? '') !== '') {
            return PhoneReading::refused(PhoneProblem::CarriesAnExtension);
        }

        if (!$this->numbers->isValidNumber($number)) {
            // The number's own country where it has one, so that a value written
            // `+41 …` is refused in the name of Switzerland even on an
            // installation that has never said where it is.
            return PhoneReading::refused(
                PhoneProblem::NotDiallable,
                $this->numbers->getRegionCodeForNumber($number) ?? $region,
            );
        }

        return PhoneReading::number($this->numbers->format($number, self::STORED));
    }

    /**
     * A stored number as the person in front of it would write it.
     *
     * **National where it is local, international where it is not.** A Swiss
     * number on a Swiss reader's screen is `079 123 45 67` — the way it is
     * written on a business card, dialled from a desk phone and read out loud —
     * and the same number on a German reader's screen is `+41 79 123 45 67`,
     * because the country code is the part they need and the leading zero is the
     * part they must not dial. The comparison is the number's country against
     * the reader's, so a German number shown to a German reader gets the same
     * courtesy in the other direction.
     *
     * Anything unreadable comes back as it was stored rather than as an empty
     * cell. A row holding something this build cannot parse — written before
     * this field type existed, or valid under an older metadata release — should
     * still show a customer their own data.
     *
     * @param string|null $readerRegion the country the reader is in, or null when
     *                                  nobody knows; see
     *                                  {@see \Xivi\Core\Field\Type\PhoneFieldType::display()}
     *                                  for where that comes from
     */
    public function display(string $stored, ?string $readerRegion): string
    {
        $number = $this->tryParse($stored);

        if ($number === null) {
            return $stored;
        }

        $local = $readerRegion !== null
            && $this->numbers->getRegionCodeForNumber($number) === strtoupper($readerRegion);

        return $this->numbers->format(
            $number,
            $local ? PhoneNumberFormat::NATIONAL : PhoneNumberFormat::INTERNATIONAL,
        );
    }

    /**
     * A real number from this country, for a placeholder and for demo data.
     *
     * Google ships one example per country per kind of line, so the placeholder
     * under a phone box is a number of the shape that country actually writes
     * rather than a Swiss one shown to a Dutch customer. Mobile first because
     * that is what a contact record usually holds, and a fixed line where a
     * country has no mobile example.
     *
     * `$vary` is what keeps demo data from being fifty thousand copies of one
     * number: the last digits are replaced with a counter and the result is
     * checked before it is offered, so a country whose numbering makes those
     * digits meaningful falls back to the example itself rather than producing
     * something unringable.
     */
    public function example(?string $region, ?int $vary = null): ?string
    {
        if ($region === null || $region === '') {
            return null;
        }

        $example = $this->numbers->getExampleNumberForType($region, PhoneNumberType::MOBILE)
            ?? $this->numbers->getExampleNumberForType($region, PhoneNumberType::FIXED_LINE);

        if ($example === null) {
            return null;
        }

        $formatted = $this->numbers->format($example, self::STORED);

        if ($vary === null) {
            return $formatted;
        }

        $national = (string) $example->getNationalNumber();
        $tail = min(5, max(0, \strlen($national) - 3));

        if ($tail === 0) {
            return $formatted;
        }

        $candidate = sprintf(
            '+%d%s%s',
            $example->getCountryCode(),
            substr($national, 0, \strlen($national) - $tail),
            str_pad((string) ($vary % 10 ** $tail), $tail, '0', \STR_PAD_LEFT),
        );

        // Checked rather than trusted: the digits after a mobile prefix are free
        // in most numbering plans and not in all of them, and demo data that
        // this field type would itself refuse is worse than repetitive demo
        // data.
        return $this->read($candidate, null)->e164 ?? $formatted;
    }

    /** The country a stored number belongs to, or null if it is not one. */
    public function regionOf(string $stored): ?string
    {
        $number = $this->tryParse($stored);

        return $number === null ? null : $this->numbers->getRegionCodeForNumber($number);
    }

    /**
     * A stored value back into a number, forgivingly.
     *
     * Everything that reaches here has already been through {@see read()} on the
     * way in — but "already been through" is a claim about every row ever
     * written, including the ones a `text` field wrote before this type existed
     * (§6.1: a customer's definitions are the truth, and they change under their
     * data). So the read paths tolerate what the write path refuses.
     */
    private function tryParse(string $stored): ?PhoneNumber
    {
        try {
            return $this->numbers->parse($stored, null);
        } catch (NumberParseException) {
            return null;
        }
    }
}

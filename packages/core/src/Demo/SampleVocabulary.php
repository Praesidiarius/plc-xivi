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

namespace Xivi\Core\Demo;

/**
 * Words for demo data, chosen by what a field is *called*.
 *
 * A deliberate heuristic, and worth being honest about: the engine knows a field
 * is text, never that it holds a surname. Everything else in Xivi is driven by
 * the definitions and refuses to guess — but demo data that reads
 * "lorem ipsum, lorem" tells you nothing about whether a list of contacts is
 * usable, and a generator is a development tool rather than part of the model.
 *
 * So this matches on the field key and falls back to filler when it recognises
 * nothing. Being wrong costs a silly-looking demo record and nothing else. No
 * module declares anything for it, and nothing outside demo generation reads it.
 *
 * Swiss by default, because that is who this is being built for: names, towns
 * and streets that look like the data a real customer here would have, which is
 * also what makes sorting and filtering look right when you try them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SampleVocabulary
{
    public const array FIRST_NAMES = [
        'Anna', 'Peter', 'Maria', 'Hans', 'Ursula', 'Daniel', 'Sandra', 'Martin',
        'Nicole', 'Thomas', 'Claudia', 'Andreas', 'Barbara', 'Stefan', 'Monika',
        'Marco', 'Franziska', 'Reto', 'Simone', 'Beat', 'Nadine', 'Christian',
        'Petra', 'Lukas', 'Céline', 'Fabian', 'Jasmin', 'Patrick', 'Corinne', 'Urs',
    ];

    public const array LAST_NAMES = [
        'Müller', 'Meier', 'Schmid', 'Keller', 'Weber', 'Huber', 'Schneider',
        'Meyer', 'Steiner', 'Fischer', 'Brunner', 'Baumann', 'Frei', 'Zimmermann',
        'Moser', 'Widmer', 'Wyss', 'Graf', 'Roth', 'Suter', 'Bühler', 'Kaufmann',
        'Hofer', 'Egger', 'Lehmann', 'Bachmann', 'Gerber', 'Studer', 'Marti', 'Koch',
    ];

    public const array COMPANY_SUFFIXES = ['AG', 'GmbH', 'SA', 'Sàrl', '& Partner', 'Holding AG'];

    public const array COMPANY_WORDS = [
        'Alpin', 'Helvetia', 'Nordwand', 'Seefeld', 'Rheintal', 'Matterhorn',
        'Limmat', 'Aare', 'Jura', 'Säntis', 'Gotthard', 'Bodensee', 'Emmental',
    ];

    public const array CITIES = [
        'Zürich', 'Bern', 'Basel', 'Genève', 'Lausanne', 'Winterthur', 'Luzern',
        'St. Gallen', 'Lugano', 'Biel', 'Thun', 'Chur', 'Fribourg', 'Schaffhausen',
        'Zug', 'Sion', 'Neuchâtel', 'Aarau', 'Solothurn', 'Frauenfeld',
    ];

    public const array STREETS = [
        'Bahnhofstrasse', 'Hauptstrasse', 'Dorfstrasse', 'Kirchgasse', 'Marktgasse',
        'Seestrasse', 'Bergstrasse', 'Industriestrasse', 'Schulhausstrasse',
        'Rathausplatz', 'Poststrasse', 'Feldweg', 'Lindenweg', 'Sonnenbergstrasse',
    ];

    public const array COUNTRIES = ['CH', 'DE', 'AT', 'FR', 'IT', 'LI'];

    public const array LABELS = ['Home', 'Work', 'Billing', 'Delivery', 'Holiday'];

    public const array FILLER = [
        'Generated for testing', 'Sample record', 'Placeholder text',
        'Demo entry', 'Not real data', 'For development only',
    ];

    /**
     * The list a field of this name should draw from.
     *
     * Matched on substrings rather than exact keys, so `email_private`,
     * `billing_city` and `contact_first_name` all land somewhere sensible without
     * anybody enumerating them.
     *
     * @return list<string>
     */
    public static function forKey(string $key): array
    {
        $key = mb_strtolower($key);

        return match (true) {
            str_contains($key, 'first_name') || str_contains($key, 'firstname') => self::FIRST_NAMES,
            str_contains($key, 'last_name') || str_contains($key, 'lastname'),
            str_contains($key, 'surname') => self::LAST_NAMES,
            str_contains($key, 'company') || str_contains($key, 'organisation'),
            str_contains($key, 'organization') => self::COMPANY_WORDS,
            str_contains($key, 'city') || str_contains($key, 'town'),
            str_contains($key, 'ort') => self::CITIES,
            str_contains($key, 'street') || str_contains($key, 'address'),
            str_contains($key, 'strasse') => self::STREETS,
            str_contains($key, 'country') || str_contains($key, 'land') => self::COUNTRIES,
            str_contains($key, 'label') || str_contains($key, 'kind'),
            str_contains($key, 'type') => self::LABELS,
            // A name that is not a person's is most likely a thing's.
            str_contains($key, 'name') || str_contains($key, 'title') => self::COMPANY_WORDS,
            default => self::FILLER,
        };
    }

    /**
     * One of a list, from the seeded generator.
     *
     * `mt_rand` rather than `random_int` on purpose: demo data is not a secret,
     * and a seeded sequence is what makes "it broke on record 4,312" something
     * you can reproduce.
     *
     * @param list<string> $values
     */
    public static function oneOf(array $values): string
    {
        return $values === [] ? '' : $values[mt_rand(0, \count($values) - 1)];
    }

    /** A company name, which is two words and a suffix rather than one. */
    public static function company(): string
    {
        return sprintf(
            '%s %s',
            self::oneOf(self::COMPANY_WORDS),
            self::oneOf(self::COMPANY_SUFFIXES),
        );
    }
}

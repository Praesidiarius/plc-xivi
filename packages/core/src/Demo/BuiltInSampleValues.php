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
 * Small word lists, so that demo data works with no dependency at all.
 *
 * The fallback rather than the intended thing: nine hundred name combinations
 * are enough to see that a page renders and nowhere near enough to see how a
 * list of twenty thousand behaves. Development replaces this with the
 * Faker-backed implementation, which is why the lists here are kept short rather
 * than being grown by hand forever.
 *
 * Swiss, because that is who this is for, and because data that looks like a
 * real customer's is what makes a list look wrong when it is wrong.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class BuiltInSampleValues implements SampleValues
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

    public function firstName(): string
    {
        return self::oneOf(self::FIRST_NAMES);
    }

    public function lastName(): string
    {
        return self::oneOf(self::LAST_NAMES);
    }

    public function company(): string
    {
        return self::oneOf(self::COMPANY_WORDS) . ' ' . self::oneOf(self::COMPANY_SUFFIXES);
    }

    public function city(): string
    {
        return self::oneOf(self::CITIES);
    }

    public function street(): string
    {
        return self::oneOf(self::STREETS) . ' ' . mt_rand(1, 140);
    }

    public function country(): string
    {
        return self::oneOf(self::COUNTRIES);
    }

    public function phone(): string
    {
        return sprintf('+41 %d%d %d%d%d %d%d %d%d', ...array_map(
            static fn (): int => mt_rand(0, 9),
            range(1, 9),
        ));
    }

    public function label(): string
    {
        return self::oneOf(self::LABELS);
    }

    public function filler(): string
    {
        return self::oneOf(self::FILLER);
    }

    /**
     * `mt_rand` rather than `random_int`: demo data is not a secret, and a
     * seedable sequence is what makes "it broke on record 4,312" reproducible.
     *
     * @param list<string> $values
     */
    private static function oneOf(array $values): string
    {
        return $values === [] ? '' : $values[mt_rand(0, \count($values) - 1)];
    }
}

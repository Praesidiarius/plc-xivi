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

namespace App\Demo;

use Faker\Factory;
use Faker\Generator;
use Xivi\Core\Demo\SampleValues;

/**
 * The words demo data is made of, from Faker.
 *
 * Registered in dev and test only, replacing core's built-in lists. Those hold
 * thirty first names and thirty surnames — nine hundred combinations, which
 * twenty thousand generated people saturate completely, leaving one name
 * repeated thirty-five times. Faker's Swiss locale has hundreds of each, so a
 * list of twenty thousand sorts and groups like a list of twenty thousand.
 *
 * It is a **dev dependency**, which is the whole reason for the interface: core
 * cannot require it without shipping a demo-data library in every production
 * image, for a code path production never runs. `config/services.yaml` excludes
 * this class from the production container for the same reason the demo commands
 * are excluded.
 *
 * Seeding still works. Faker draws from `mt_rand`, which the generator seeds
 * once per run, so the same seed still produces the same database.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FakerSampleValues implements SampleValues
{
    private readonly Generator $faker;

    public function __construct()
    {
        // de_CH: Swiss names, towns and postcodes, which is what a customer here
        // would actually have — and what makes a wrong-looking list look wrong.
        $this->faker = Factory::create('de_CH');
    }

    public function firstName(): string
    {
        return $this->faker->firstName();
    }

    public function lastName(): string
    {
        return $this->faker->lastName();
    }

    public function company(): string
    {
        return $this->faker->company();
    }

    public function city(): string
    {
        return $this->faker->city();
    }

    public function street(): string
    {
        return $this->faker->streetAddress();
    }

    /**
     * Deliberately not Faker's `countryCode()`, which draws from every country
     * there is and fills a Swiss customer list with Malawi and the Cook Islands.
     * A neighbour or two is what makes a country column worth having at all.
     */
    public function country(): string
    {
        return $this->faker->randomElement(['CH', 'CH', 'CH', 'DE', 'AT', 'FR', 'IT', 'LI']);
    }

    public function phone(): string
    {
        return $this->faker->phoneNumber();
    }

    /**
     * Faker has no notion of "the name of one of several rows", so this stays
     * the built-in short list — which is the right length for it anyway.
     */
    public function label(): string
    {
        return $this->faker->randomElement(['Home', 'Work', 'Billing', 'Delivery', 'Holiday']);
    }

    public function filler(): string
    {
        return $this->faker->sentence(4);
    }
}

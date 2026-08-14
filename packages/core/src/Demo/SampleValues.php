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
 * Where the words in demo data come from.
 *
 * An interface because the good vocabulary lives in a library that has no
 * business being in a production image. Core ships small built-in lists so that
 * generating demo data works anywhere with no dependency at all; development
 * swaps in a Faker-backed implementation with thousands of names behind it.
 *
 * The difference is not cosmetic. The built-in lists hold thirty first names and
 * thirty surnames, which is nine hundred combinations — twenty thousand
 * generated people saturate that completely, and testing how a list sorts or
 * groups against data with nine hundred distinct values is testing the wrong
 * thing.
 *
 * Deliberately not a method per field: this returns *words*, and deciding which
 * kind of word a field wants belongs to SampleVocabulary, where it is the same
 * question whichever implementation is behind this.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface SampleValues
{
    public function firstName(): string;

    public function lastName(): string;

    /** A whole company name, legal form included — "Alpin AG", not "Alpin". */
    public function company(): string;

    public function city(): string;

    /** A street with a number on it. */
    public function street(): string;

    /** An ISO country code, since that is what a country field usually holds. */
    public function country(): string;

    public function phone(): string;

    /** The name somebody gives one of several rows: "Home", "Billing". */
    public function label(): string;

    /** For a field whose name says nothing about what belongs in it. */
    public function filler(): string;
}

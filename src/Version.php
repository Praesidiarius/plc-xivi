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

namespace App;

/**
 * What version of Xivi this is.
 *
 * The single place the number is written down. `config/packages/twig.yaml`
 * exposes this same constant to templates with `!php/const`, rather than
 * repeating it in configuration where the two would drift apart.
 *
 * **The leading 17 is a generation, not a semver major.** It says which Xivi
 * this is, and changes only when there is a new one — a business decision rather
 * than a technical one. Breaking changes inside a generation do not touch it,
 * which is a deliberate departure from semantic versioning and the reason this
 * comment exists.
 *
 * The two numbers after it move on **release**, not on feature: the middle one
 * when a version is cut that somebody would be told about, the last one for
 * fixes to a version already out there. Work in progress accumulates under
 * "Unreleased" in CHANGELOG.md and moves nothing, so the number cannot creep on
 * its own — cutting a release is something a person does.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class Version
{
    public const string CURRENT = '17.0.0';

    /** The generation, for anywhere that wants to say "Xivi 17". */
    public const string GENERATION = '17';
}

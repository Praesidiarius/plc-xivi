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
 * The two numbers after it move on **release**, not on feature. The last one is
 * the release counter and moves every time one is cut — which while this project
 * is going at its current pace is roughly daily, so a year of it looks like
 * 17.0.351 rather than like semver. The middle one is for a release big enough
 * to be worth naming, and has not moved yet.
 *
 * Deliberately *not* "patches are fixes". That rule was here first and was
 * already false the day two features shipped under it; a version scheme nobody
 * follows is worse than an unusual one everybody does.
 *
 * Work in progress accumulates under "Unreleased" in CHANGELOG.md and moves
 * nothing, so the number cannot creep on its own — cutting a release is
 * something a person does.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class Version
{
    public const string CURRENT = '17.0.6';

    /** The generation, for anywhere that wants to say "Xivi 17". */
    public const string GENERATION = '17';
}

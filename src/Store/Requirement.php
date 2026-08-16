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

namespace App\Store;

/**
 * One module another module cannot work without, and whether this customer has
 * it (XIV-23, XIV-6).
 *
 * Named rather than chained, which is decision three of XIV-6 and worth the
 * sentence: installing Invoice could perfectly well install Contact and Order on
 * the way past, and it must not. Each of those carries its own preset choice, and
 * a preset cannot be changed afterwards (§6.1) — so a chain would make two
 * permanent decisions on somebody's behalf while they thought they were making
 * one. Telling them what is missing, with a link to it, costs two clicks and
 * leaves both decisions theirs.
 *
 * `$offered` is why the link is conditional. A requirement that is not in the
 * store is a real state — a module in development, or one this build ships that
 * nobody has published — and a link to a page saying "no such module" would be
 * worse than the plain name.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Requirement
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $installed,
        public bool $offered,
    ) {
    }
}

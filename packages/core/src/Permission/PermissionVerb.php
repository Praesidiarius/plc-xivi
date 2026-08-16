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

namespace Xivi\Core\Permission;

/**
 * A thing that can be granted, whichever vocabulary it comes from (§8.4, XIV-6).
 *
 * For most of this system's life there was one vocabulary — ModuleAction, view
 * through transition, crossed with the modules a customer has installed — and a
 * grant was a sentence in it. The store broke that, and not by accident: its two
 * verbs are *browse*, which names no module at all, and *install*, which names
 * one the customer specifically does **not** have. Neither is a thing that can be
 * done to a module's records, so neither belongs in an enum whose whole subject
 * is a module's records.
 *
 * So there are now two vocabularies, and this is what they have in common. It is
 * deliberately tiny: everything the grant table, the resolver and the admin
 * matrix need of a verb, and nothing about what it means. A vocabulary knows
 * what its own words are for; this only knows that a word can be granted.
 *
 * **Values across all vocabularies are disjoint**, which is not a coincidence
 * anybody has to remember: `PermissionCoverageTest` fails the build if two of
 * them ever collide. That disjointness is what lets one `varchar(16)` column hold
 * either kind and be read back unambiguously, and it is why adding an axis needs
 * no migration, exactly as adding an action never did.
 *
 * `\BackedEnum` rather than a bare interface: a verb has to be a stored string,
 * and inheriting that from PHP is better than declaring a `value()` method beside
 * the `value` property every implementation already has. The cost is that
 * `$verb->value` is typed `string|int` here, so the few places building an array
 * key from it say `(string)` out loud.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface PermissionVerb extends \BackedEnum
{
    /**
     * Whether "only the records I own" is a question this verb can answer.
     *
     * False for everything that names no existing record — adding, importing,
     * and every one of the store's verbs, since a module the customer has not
     * got has no records to own.
     */
    public function isScopable(): bool;

    /** How the permission screens name it. */
    public function labelKey(): string;
}

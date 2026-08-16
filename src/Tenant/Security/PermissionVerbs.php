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

namespace App\Tenant\Security;

use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionVerb;

/**
 * The one place that knows there is more than one permission axis (§8.4.3,
 * XIV-6).
 *
 * A grant is a subject, a verb and a scope, and until the store there was exactly
 * one vocabulary the verb could come from. Now there are two, and something has
 * to answer two questions about a `('contact', 'view')` or a `('@store',
 * 'install')` that arrives as a pair of strings out of a form or a route
 * attribute:
 *
 * * **which vocabulary is this word in** — {@see tryFrom()}
 * * **is that word one this subject accepts** — {@see accepts()}
 *
 * The second is the one that would otherwise be missing. A permission matrix is
 * generated from what the customer has, so nothing legitimate ever posts an
 * incoherent pair; a hand-edited request is a different matter, and without this
 * it could write `('contact', 'install')` — a row nothing would ever read, sitting
 * in the table looking like an authority somebody has. Grants are the only thing
 * the permission system stores, so the one rule worth enforcing at the write side
 * is that a stored grant says something.
 *
 * **Both answers are derived, never listed.** The subject decides: `@store` is the
 * store's, another `@` key is an area's, and anything else is a module key — which
 * is why installing a module needs nothing seeded here, exactly as §8.4 promised
 * about the catalogue.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PermissionVerbs
{
    /**
     * The verb this string names, in whichever vocabulary has it, or null.
     *
     * The vocabularies are disjoint by rule rather than by luck — see
     * PermissionVerb, and the test that fails the build if that ever stops being
     * true — so the string alone identifies the verb and the order of these two
     * lookups cannot matter.
     */
    public static function tryFrom(string $verb): ?PermissionVerb
    {
        return ModuleAction::tryFrom($verb) ?? StoreAction::tryFrom($verb);
    }

    /**
     * Every verb that means something about this subject.
     *
     * @return list<PermissionVerb>
     */
    public static function acceptedBy(string $subject): array
    {
        if ($subject === StoreAction::SUBJECT) {
            return StoreAction::cases();
        }

        $area = PermissionArea::tryFrom($subject);

        // A module key, which is anything the areas and the store have not
        // claimed. Unknown module keys are not refused here: a grant naming a
        // module the customer later uninstalls goes inert rather than cascading
        // away (§8.4), so "no such module" is not a thing this can answer.
        return $area?->actions() ?? ModuleAction::cases();
    }

    /** Whether granting this verb on this subject would say anything at all. */
    public static function accepts(string $subject, PermissionVerb $verb): bool
    {
        return \in_array($verb, self::acceptedBy($subject), true);
    }
}

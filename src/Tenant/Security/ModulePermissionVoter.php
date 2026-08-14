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

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Xivi\Core\Permission\ModuleAction;

/**
 * May this person do this to *any* of a module's records (§7.5).
 *
 * The coarse half of the model, and the one a route can be annotated with,
 * because the module key is in the URL and needs nothing loaded to be known.
 * `#[IsGranted(ModuleAction::List->value, subject: 'module')]` on a controller
 * method is a check that happens before the action runs at all.
 *
 * It deliberately says nothing about *which* records — that is
 * RecordPermissionVoter for one of them, and the query predicate for a page of
 * them (Xivi\Core\Permission\RecordAccess). Somebody scoped to their own records
 * still passes this: they may list, they will simply be shown fewer.
 *
 * The attribute is a string because Voter::supports() is typed that way and
 * Voter::vote() quietly abstains on the TypeError otherwise — see ModuleAction,
 * which is where the string comes from.
 *
 * @extends Voter<string, string>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModulePermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionResolver $permissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // A module key, which is what a route hands over. Anything that is not
        // one of this enum's values is somebody else's attribute.
        return \is_string($subject) && ModuleAction::tryFrom($attribute) !== null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert(\is_string($subject));

        return $this->permissions
            ->forUser($token->getUser())
            ->allows($subject, ModuleAction::from($attribute));
    }
}

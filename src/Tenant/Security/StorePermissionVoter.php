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

/**
 * May this person browse the store, and may they install out of it (§8.4.3,
 * XIV-6).
 *
 * The second axis's enforcement seam, and a second voter rather than two more
 * branches inside {@see ModulePermissionVoter}. That one's whole subject is a
 * module's records — its name says so and so does every line of its docblock —
 * and teaching it a vocabulary about something else would have made it the class
 * that knows about both axes, which is a job {@see PermissionVerbs} already does
 * in one place.
 *
 * Structurally it is the same shape as its sibling, because the model underneath
 * is genuinely the same: a subject, a verb, and a resolved set holding grants
 * that only ever add. What differs is which words it answers to, which is exactly
 * what "a second axis" means.
 *
 * The routes name `@store` as their subject through a route default, the way
 * {@see \App\Controller\TenantProfileController} names its area — the check has
 * to happen before the action runs, and `#[IsGranted]` needs something to point
 * at (§8.4).
 *
 * @extends Voter<string, string>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class StorePermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionResolver $permissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Both halves are checked. The attribute alone would be enough today,
        // since the two vocabularies are disjoint, but a store verb voted on
        // against a module key is a wiring mistake and abstaining on it means
        // denied — which is the safe direction and a bug worth not hiding.
        return $subject === StoreAction::SUBJECT && StoreAction::tryFrom($attribute) !== null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->permissions
            ->forUser($token->getUser())
            ->allows(StoreAction::SUBJECT, StoreAction::from($attribute));
    }
}

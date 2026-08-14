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

use App\Tenant\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * May this person do this to *this* record (§7.5).
 *
 * The fine half. It answers about a record already loaded, which is precisely
 * what a voter can do and precisely what it cannot be asked to do for a list —
 * by the time a voter runs on a page of twenty-five, the twenty-five have been
 * fetched and counted. That is why the list goes through a WHERE clause instead
 * (Xivi\Core\Permission\RecordAccess), and why the two must agree.
 *
 * They agree because both ask the same question of the same resolved set: does
 * the scope say All, or does this record's owner match. A record owned by
 * nobody matches nobody — the same answer the predicate gives, and deliberately
 * not "everybody", which is what an unowned row would drift into if either side
 * treated null as a wildcard.
 *
 * @extends Voter<string, ModuleRecord>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordPermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionResolver $permissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ModuleRecord && ModuleAction::tryFrom($attribute) !== null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof ModuleRecord);

        $user = $token->getUser();
        $action = ModuleAction::from($attribute);
        $scope = $this->permissions->forUser($user)->scopeFor($subject->module->getKey(), $action);

        if ($scope === null) {
            return false;
        }

        if ($scope === PermissionScope::All) {
            return true;
        }

        // Scoped to their own. An owner of null is not a match: a record nobody
        // owns belongs to nobody, not to whoever asks first.
        $userId = $user instanceof User ? $user->getId() : null;

        return $userId !== null && $subject->record->ownerId === $userId;
    }
}

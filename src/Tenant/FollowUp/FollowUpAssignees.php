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

namespace App\Tenant\FollowUp;

use App\Tenant\Entity\User;
use App\Tenant\Security\PermissionResolver;
use App\Tenant\Security\UserManager;
use Xivi\Core\Permission\ModuleAction;

/**
 * Who the assignee picker is allowed to offer (XIV-82).
 *
 * §5.18's rule is that a follow-up may only be assigned to somebody who could
 * open the record it sits on, and {@see FollowUpManager::assertAssigneeMayView()}
 * refuses on the write path when it is broken. That refusal is the enforcement
 * and this class is not: **this is the courtesy that keeps the refusal from ever
 * being reached.** A picker listing everybody would offer a name, take the form,
 * and answer "that person may not see this record" — a thing the screen knew
 * before it drew the option.
 *
 * **It asks exactly the seam the write path asks, and that is the point.** The
 * temptation was to reach into the grant repository and select users with a View
 * row for the module, which is one query instead of one per person and would be
 * wrong in two ways that only show up later: administrators hold no grants at
 * all (they are a bypass — see {@see PermissionResolver}), and a deactivated
 * account resolves to nothing whatever its rows say. Both cases are decided
 * inside the resolver, so a query around it would have hidden every
 * administrator from the picker and offered every deactivated colleague. Asking
 * `PermissionResolver::forUser()` per person cannot disagree with the write path
 * because it *is* the write path's question.
 *
 * The cost is one resolution per user, and the resolver caches per user object
 * for the request, so a page that also drew the owner's name does not pay twice.
 * A customer with a thousand accounts would want something cleverer here; a
 * customer with a thousand accounts is not what this application is for yet, and
 * guessing at the cleverness before anybody has felt the slowness would be
 * paying for it twice.
 *
 * **Module-level View, never record-level.** That is the manager's rule copied
 * exactly rather than improved on: somebody scoped to their own records holds
 * View on the module and would be offered. §5.18 argued that residue belongs
 * where it shows — the widget lists such a follow-up without a link — and
 * tightening it here would put a second, stricter answer in front of a write path
 * that would have accepted it, which is the kind of disagreement between seams
 * this codebase spends its comments avoiding.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FollowUpAssignees
{
    public function __construct(
        private UserManager $users,
        private PermissionResolver $permissions,
    ) {
    }

    /**
     * Everybody who may view this module's records, by name.
     *
     * Sorted the way {@see UserManager::all()} sorts, which is by name: a picker
     * is read by somebody looking for a colleague they have already thought of,
     * and any other order makes them hunt.
     *
     * @return list<User>
     */
    public function forModule(string $moduleKey): array
    {
        return array_values(array_filter(
            $this->users->all(),
            fn (User $user): bool => $this->permissions->forUser($user)->allows($moduleKey, ModuleAction::View),
        ));
    }
}

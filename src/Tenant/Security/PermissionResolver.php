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
use App\Tenant\Repository\PermissionGrantRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Xivi\Core\Permission\PermissionSet;

/**
 * What one person may do, worked out from their groups and their own grants
 * (§7.5).
 *
 * The application half of the permission model: core is handed a resolved
 * PermissionSet and never learns what a user is, the same boundary RecordWriter
 * keeps when it stores a user id without a foreign key (§5.2).
 *
 * **Default deny.** Anybody with no grants gets nothing — not "everything until
 * configured". The upgrade path for installations that predate this is
 * `tenant:permissions:grant-all`, run deliberately per customer, rather than a
 * migration writing rows into live tenant databases.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PermissionResolver
{
    /**
     * Resolved sets for this request, keyed by the user *object*.
     *
     * Keyed by identity rather than by id, and that is not fussiness: user ids
     * are only unique within a tenant (§8.2), so a cache keyed by id would serve
     * one customer's permissions to another in any process that visits several in
     * sequence — a console command, and message consumers when they arrive. Two
     * tenants' users are never the same object, so identity cannot make that
     * mistake.
     *
     * SplObjectStorage rather than spl_object_id(), which is reused after an
     * object is collected and would eventually key a stale answer to a new user.
     *
     * @var \SplObjectStorage<UserInterface, PermissionSet>
     */
    private \SplObjectStorage $resolved;

    public function __construct(
        private readonly PermissionGrantRepository $grants,
    ) {
        $this->resolved = new \SplObjectStorage();
    }

    public function forUser(?UserInterface $user): PermissionSet
    {
        // Not signed in, or signed in as something this application did not issue.
        if (!$user instanceof User) {
            return PermissionSet::nothing();
        }

        // offsetExists() rather than contains(), which PHP 8.5 deprecates.
        if ($this->resolved->offsetExists($user)) {
            return $this->resolved[$user];
        }

        return $this->resolved[$user] = $this->resolve($user);
    }

    private function resolve(User $user): PermissionSet
    {
        // A bypass, not a group. See PermissionSet::unrestricted() for why it is
        // not modelled as one.
        if (UserManager::isAdmin($user)) {
            return PermissionSet::unrestricted();
        }

        // A deactivated account is refused at sign-in and its live session ended
        // (§8.4.1), so this is belt and braces — but a permission set is exactly
        // the wrong thing to hand out on the strength of another check being
        // right.
        if (!$user->isActive()) {
            return PermissionSet::nothing();
        }

        $set = PermissionSet::nothing();

        foreach ($this->grants->findForUser($user) as $grant) {
            $set = $set->with($grant->getModuleKey(), $grant->getAction(), $grant->getScope());
        }

        return $set;
    }
}

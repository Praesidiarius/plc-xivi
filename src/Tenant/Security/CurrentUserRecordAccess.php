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
use Symfony\Bundle\SecurityBundle\Security;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Permission\RecordAccessProvider;

/**
 * What the signed-in person may see of any module (XIV-13).
 *
 * The application half of `RecordAccessProvider`: core asks about a module it
 * has followed a link into, and this resolves it from the same grants every
 * other check uses (§8.4). Core still never learns what a user is.
 *
 * **Nobody signed in gets nothing**, not everything. A console command or a
 * message consumer following a link is not an administrator, and defaulting a
 * permission question to "yes" because there is no answer is the one direction
 * this must never fail in.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CurrentUserRecordAccess implements RecordAccessProvider
{
    public function __construct(
        private PermissionResolver $permissions,
        private Security $security,
    ) {
    }

    public function accessFor(string $moduleKey, ModuleAction $action): RecordAccess
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return RecordAccess::nothing();
        }

        return RecordAccess::fromPermissions(
            $this->permissions->forUser($user),
            $moduleKey,
            $action,
            $user->getId(),
        );
    }
}

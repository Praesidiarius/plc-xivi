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
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A deactivated user is not allowed in.
 *
 * `User::active` existed from the beginning and, until this class, nothing read
 * it: the column was written, shown and never enforced, so "deactivated" meant
 * nothing at all. A user manager whose deactivate button did not lock anybody out
 * would be worse than not having one, because somebody would rely on it.
 *
 * This covers signing *in* only. A user checker is not consulted when a session
 * is restored on a later request — `ContextListener` compares identifier,
 * password and roles and nothing else — so somebody deactivated while signed in
 * is dealt with by `DeactivatedUserListener` instead. Both are needed; neither
 * covers the other's case.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        $this->refuseInactive($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->refuseInactive($user);
    }

    private function refuseInactive(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActive()) {
            // Deliberately says the account is deactivated rather than pretending
            // the credentials were wrong. Somebody whose access was withdrawn
            // needs to know to ask a colleague, and hiding it only sends them to
            // the password reset that will not help.
            throw new CustomUserMessageAccountStatusException('This account has been deactivated.');
        }
    }
}

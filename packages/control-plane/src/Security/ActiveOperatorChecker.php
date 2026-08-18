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

namespace Xivi\ControlPlane\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Xivi\ControlPlane\Entity\Operator;

/**
 * A revoked operator is not allowed in (XIV-92).
 *
 * The control plane's half of the pair `App\Tenant\Security\ActiveUserChecker`
 * makes on the tenant side, wired onto the `control_plane` firewall in
 * `security.yaml`. Without it `Operator::active` would be a column that is
 * written, listed and never enforced — which is worse than not having the column
 * at all, because somebody would revoke an account and believe it.
 *
 * **It is a separate class from the tenant one rather than a shared checker
 * handling both entity types.** The two firewalls are kept apart on purpose
 * (§8.9): one class reading `App\Tenant\Entity\User` *and*
 * `Xivi\ControlPlane\Entity\Operator` would be a single object holding the rule
 * for both sides of a boundary this codebase spends a section arguing should
 * have nothing in common, and `deptrac` would have to be told that the tenant
 * application may reach into the control-plane package to get it. Fifteen lines
 * duplicated is the cheaper half of that trade.
 *
 * **This covers signing *in* only.** A user checker is not consulted when a
 * session is restored on a later request — `ContextListener` compares
 * identifier, password and roles and nothing else — so an operator revoked while
 * signed in is dealt with by {@see \Xivi\ControlPlane\EventListener\RevokedOperatorListener}
 * instead. Both are needed; neither covers the other's case, and that was
 * established by watching the test for the second one fail with this class
 * already in place.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ActiveOperatorChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        $this->refuseRevoked($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->refuseRevoked($user);
    }

    private function refuseRevoked(UserInterface $user): void
    {
        if ($user instanceof Operator && !$user->isActive()) {
            // Says the access was withdrawn rather than pretending the password
            // was wrong, which is the same choice `ActiveUserChecker` makes and
            // is easier to defend here: the sign-in form itself is unreachable
            // from anywhere but the control-plane host (§8.9), so this sentence
            // is not shown to anybody who was not already told that a control
            // plane exists. Hiding it would only send a former operator round
            // the password-reset loop that does not exist here.
            throw new CustomUserMessageAccountStatusException('This operator account has been revoked.');
        }
    }
}

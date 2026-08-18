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

/**
 * A change to an operator that the control plane will not make (XIV-92).
 *
 * The sibling of `App\Tenant\Security\UserChangeRefused`, and deliberately the
 * poorer relation of it in one respect: that class carries a
 * `TranslatableMessage` beside the exception message, because a tenant
 * administrator causes those refusals from a screen and has to read them in
 * their own language. Every refusal here is caused at a console by whoever runs
 * this installation, and there is no control-plane screen that can make one.
 * Adding a translation for a sentence nobody but its author ever reads would be
 * a catalogue key to maintain in return for nothing — and if a screen ever does
 * make one of these, the missing half is a compile-time absence rather than a
 * silently English page.
 *
 * Its own class rather than `\RuntimeException` for the reason
 * {@see OperatorAlreadyExists} is: the console has to tell a refusal apart from
 * a database that is down, and a caught `\RuntimeException` catches both.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OperatorChangeRefused extends \RuntimeException
{
    /**
     * The one refusal in this file that is about the installation rather than
     * about one row.
     *
     * Revoking the last operator who can still sign in leaves nobody at all: the
     * control plane has no sign-up, no invitation and no password reset, so the
     * web has no way back in. The console does — `control:operator:create` still
     * works, and whoever could have typed the revocation can type that — which is
     * why this is a guard against an accident rather than against a
     * catastrophe, and why it is worded as the next step to take rather than as
     * a prohibition.
     */
    public static function lastOperator(string $email): self
    {
        return new self(sprintf(
            'Refusing to revoke "%s": they are the only operator who can still sign in, and the '
            . 'control plane has no sign-up, no invitation and no password reset to get back in with. '
            . 'Create the replacement first, then revoke this one.',
            $email,
        ));
    }

    public static function unknownOperator(string $email): self
    {
        return new self(sprintf('No operator has the address "%s".', $email));
    }

    public static function alreadyRevoked(string $email): self
    {
        return new self(sprintf('Operator "%s" has already been revoked.', $email));
    }

    public static function notRevoked(string $email): self
    {
        return new self(sprintf('Operator "%s" is not revoked; there is nothing to restore.', $email));
    }

    public static function passwordTooShort(int $minimum): self
    {
        return new self(sprintf('An operator password must be at least %d characters.', $minimum));
    }
}

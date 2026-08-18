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
 * The one refusal creating an operator can make (XIV-57).
 *
 * Its own class rather than a bare `RuntimeException` for the reason the tenant
 * side has `UserAlreadyExists`: the console has to tell this apart from a
 * database that is down, and a caught `\RuntimeException` catches both.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OperatorAlreadyExists extends \RuntimeException
{
    public static function withEmail(string $email): self
    {
        return new self(sprintf(
            'An operator with the address "%s" already exists. Use "control:operator:password %1$s" '
            . 'to change their password; creating is deliberately not a way to do that (XIV-92).',
            $email,
        ));
    }

    /**
     * The same refusal, for an address whose operator has been revoked (XIV-92).
     *
     * Its own sentence rather than the one above, because the two situations
     * look identical from the terminal and want opposite next steps. Somebody
     * who types `create` against a live account has probably forgotten a
     * password; somebody who types it against a revoked one is trying to let a
     * person back in, and would otherwise read "already exists" as "this address
     * works", which is exactly what it does not do.
     *
     * Naming the restore command here is a small disclosure — it says that this
     * address once had an operator — and it is not a disclosure to anybody:
     * reaching this line means already having a shell on the machine the
     * installation runs on.
     */
    public static function revoked(string $email): self
    {
        return new self(sprintf(
            'An operator with the address "%s" exists but has been revoked. Use '
            . '"control:operator:restore %1$s" to give them access again, or '
            . '"control:operator:password %1$s" to set a new password first; creating over a '
            . 'revoked account is deliberately refused, because that would undo a revocation '
            . 'with a command that never mentions one (XIV-92).',
            $email,
        ));
    }
}

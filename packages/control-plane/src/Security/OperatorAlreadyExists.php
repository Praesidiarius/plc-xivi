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
        return new self(sprintf('An operator with the address "%s" already exists.', $email));
    }
}

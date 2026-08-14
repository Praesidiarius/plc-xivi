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

use App\ControlPlane\Entity\Tenant;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UserAlreadyExists extends \RuntimeException
{
    public static function in(Tenant $tenant, string $email, ?\Throwable $previous = null): self
    {
        return new self(sprintf(
            'Tenant "%s" already has a user with the email "%s".',
            $tenant->getSlug(),
            $email,
        ), previous: $previous);
    }
}

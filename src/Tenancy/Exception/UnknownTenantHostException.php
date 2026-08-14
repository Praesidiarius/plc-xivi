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

namespace App\Tenancy\Exception;

/**
 * No tenant is registered for the requested hostname.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UnknownTenantHostException extends \RuntimeException
{
    public function __construct(public readonly string $hostname)
    {
        parent::__construct(sprintf('No tenant is registered for host "%s".', $hostname));
    }
}

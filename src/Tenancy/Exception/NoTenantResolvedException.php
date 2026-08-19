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
 * Something asked for the current tenant when none was resolved — a bug, not a
 * runtime condition. Notably thrown when a tenant database connection is opened
 * outside a tenant context, which is the failure mode docs/architecture/open-questions.md §7.4 exists to
 * prevent: silently connecting to the wrong (or previous) tenant's database.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NoTenantResolvedException extends \LogicException
{
    public static function create(): self
    {
        return new self(
            'No tenant is resolved in the current context. A tenant connection may only be opened '
            . 'after TenantContext has been populated (by the request listener, or explicitly in a '
            . 'console command).',
        );
    }
}

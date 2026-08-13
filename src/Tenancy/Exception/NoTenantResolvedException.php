<?php

declare(strict_types=1);

namespace App\Tenancy\Exception;

/**
 * Something asked for the current tenant when none was resolved — a bug, not a
 * runtime condition. Notably thrown when a tenant database connection is opened
 * outside a tenant context, which is the failure mode docs/architecture.md §7.4 exists to
 * prevent: silently connecting to the wrong (or previous) tenant's database.
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

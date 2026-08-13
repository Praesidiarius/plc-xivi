<?php

declare(strict_types=1);

namespace App\Tenancy\Exception;

/** No tenant is registered for the requested hostname. */
final class UnknownTenantHostException extends \RuntimeException
{
    public function __construct(public readonly string $hostname)
    {
        parent::__construct(sprintf('No tenant is registered for host "%s".', $hostname));
    }
}

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

namespace Xivi\ControlPlane\Provisioning;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ProvisioningFailed extends \RuntimeException
{
    public static function invalidSlug(string $slug): self
    {
        return new self(sprintf(
            'Invalid tenant slug "%s": it becomes a database name, so it must start with a letter '
            . 'and contain only lowercase letters, digits and underscores (max 63 characters).',
            $slug,
        ));
    }

    public static function slugTaken(string $slug): self
    {
        return new self(sprintf('A tenant with slug "%s" already exists.', $slug));
    }

    public static function noHostname(string $slug): self
    {
        return new self(sprintf('Tenant "%s" needs at least one hostname to be reachable.', $slug));
    }

    public static function hostnameTaken(string $hostname): self
    {
        return new self(sprintf('Hostname "%s" is already routed to another tenant.', $hostname));
    }

    /**
     * A hostname this installation serves without a tenant (XIV-57): the control
     * plane's above all, and the loopback and container names beside it.
     */
    public static function hostnameIsReserved(string $hostname): self
    {
        return new self(sprintf(
            'Hostname "%s" is served without a tenant (app.system_hosts), so nothing routed to it '
            . 'would ever reach this customer. The control plane is one of those hosts; give the '
            . 'tenant a name of its own.',
            $hostname,
        ));
    }

    public static function dsnWithoutUser(string $slug): self
    {
        return new self(sprintf(
            'The DSN given for tenant "%s" names no database role. Each tenant connects as its own '
            . 'role, so the DSN must contain one (postgresql://role@host:5432/database).',
            $slug,
        ));
    }

    public static function databaseExists(string $database): self
    {
        return new self(sprintf(
            'Database "%s" already exists. Refusing to provision into it — drop it first, or pass an '
            . 'explicit DSN pointing at a fresh database.',
            $database,
        ));
    }
}

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

    /**
     * A hostname this installation would answer with a bare 400 (XIV-93, §4.3).
     *
     * The one refusal here that is about a *deployment* setting rather than
     * about the registry, and it exists because the alternative is silent in the
     * expensive direction: the row is created, DNS is pointed, the customer is
     * told their address, and every request to it is refused by
     * `framework.trusted_hosts` before a single line of this application runs.
     * Nothing in that sequence produces a message anybody reads, which is
     * exactly the shape {@see hostnameIsReserved()} was written against one
     * ticket earlier.
     *
     * @param list<string> $domains what `XIVI_TRUSTED_DOMAINS` currently names
     */
    public static function hostnameIsNotTrusted(string $hostname, array $domains): self
    {
        return new self(sprintf(
            'Hostname "%s" is outside the hostnames this installation answers to, so every request '
            . 'to it would be refused with an empty HTTP 400 before reaching this application. '
            . 'XIVI_TRUSTED_DOMAINS names %s and every name under them. Either give the tenant a '
            . 'hostname under one of those, or add this one\'s domain to that variable and restart '
            . '(see https://praesidiarius.github.io/plc-xivi-docs/running/hostnames/).',
            $hostname,
            implode(', ', $domains),
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

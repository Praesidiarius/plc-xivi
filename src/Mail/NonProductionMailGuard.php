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

namespace App\Mail;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Dev and test cannot put mail on the wire, structurally (XIV-37).
 *
 * §9.2 recorded the honest limit of the mail catcher this replaced: a catcher
 * sees what is pointed at it, and a DSN naming a real server is simply believed.
 * The suite provisions real tenants and stores a real SMTP credential per tenant
 * now, so "development sends to Mailpit" is a default that one fixture, one
 * hand-edited `.env.local` or one copied-in customer row is enough to walk past.
 * A default is not a guarantee, and this is meant to be a guarantee.
 *
 * **Where the guarantee lives, and why it is here rather than in configuration.**
 * Every DSN in this application becomes a transport in exactly one place:
 * `Symfony\Component\Mailer\Transport::fromDsnObject()`, which walks the services
 * tagged `mailer.transport_factory` in priority order and hands the DSN to the
 * first one that says it supports it. That is true of the instance's own
 * `MAILER_DSN`, which the framework turns into `mailer.transports` through the
 * same object, and it is true of the per-tenant SMTP credentials TenantMailer
 * builds a DSN from at request time. So one factory, registered ahead of
 * Symfony's own, sees every one of them — and nothing that could deliver is ever
 * constructed outside production, rather than being constructed and then not
 * used.
 *
 * **It only ever refuses.** `supports()` answers "would I refuse this?", so a DSN
 * this class permits falls through to Symfony's real factories untouched and
 * `create()` has nothing to do but throw. That inversion is what keeps it out of
 * the delivery path entirely: there is no branch here in which a message is sent,
 * and no wrapper object to be unwrapped by mistake.
 *
 * **What counts as unable to deliver** is deliberately a short list:
 *
 *   - the `null` scheme, which discards by construction;
 *   - `smtp`/`smtps` to a host named in `$catcherHosts` — the compose catcher and
 *     the loopback in dev (see config/services.yaml), and *nothing at all* in
 *     test, where §9.2 already refuses to read from the catcher because eight
 *     paratest workers against one inbox is a shared mutable thing again.
 *
 * Everything else is refused, `sendmail` and `native` included: both hand the
 * message to whatever MTA the machine has, which is exactly the accident this
 * exists to prevent — and no allowlist of hostnames can help there, because
 * neither DSN names one.
 *
 * The environment rather than the debug flag decides, because the environment is
 * what the kernel actually allows (`Kernel::getAllowedEnvs()`: prod, dev, test)
 * and debug is a thing production can legitimately be run with while diagnosing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NonProductionMailGuard implements TransportFactoryInterface
{
    /** The one environment allowed to reach a real mail server. */
    public const string PRODUCTION = 'prod';

    /** Schemes that reach a server over SMTP, and can therefore be judged by host. */
    private const array SMTP_SCHEMES = ['smtp', 'smtps'];

    /** @var list<string> */
    private array $catcherHosts;

    /**
     * @param string       $environment  the kernel's environment
     * @param list<string> $catcherHosts hosts known to accept mail and deliver none
     */
    public function __construct(
        private string $environment,
        array $catcherHosts = [],
    ) {
        // Compared case-insensitively, because a hostname is.
        $this->catcherHosts = array_values(array_map(strtolower(...), $catcherHosts));
    }

    /**
     * True when this DSN is one this environment may not build, i.e. when the
     * only thing left to do with it is refuse.
     */
    public function supports(Dsn $dsn): bool
    {
        if ($this->environment === self::PRODUCTION) {
            return false;
        }

        return !$this->cannotDeliver($dsn);
    }

    public function create(Dsn $dsn): TransportInterface
    {
        throw RealMailRefused::transport($dsn, $this->environment);
    }

    private function cannotDeliver(Dsn $dsn): bool
    {
        $scheme = strtolower($dsn->getScheme());

        // `null://null` and anything else the null factory answers to. Nothing
        // reaches a socket, whatever the rest of the DSN says.
        if ($scheme === 'null') {
            return true;
        }

        if (!\in_array($scheme, self::SMTP_SCHEMES, true)) {
            return false;
        }

        return \in_array(strtolower($dsn->getHost()), $this->catcherHosts, true);
    }
}

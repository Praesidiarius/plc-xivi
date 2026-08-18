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

use App\Tenancy\TenantResolver;
use Xivi\ControlPlane\Signup\SignupHost;
use Xivi\ControlPlane\Signup\SignupPage;

/**
 * Where a self-service customer is served (XIV-98).
 *
 * ### This is a decision, not a derivation, and it had to be made somewhere
 *
 * `TenantProvisioner::provision()` takes hostnames as an **explicit parameter**
 * and derives nothing from the slug — §8.12 leans on that when it argues the two
 * slug rules may differ, since an operator is free to route `acme.example.com`
 * at a tenant called `acme_ag` and nothing is inconsistent. Self-service is the
 * one case where nobody types a hostname, so somebody has to decide what it is,
 * and this ticket is the somebody.
 *
 * **The answer is the signup slug as a label under the signup host's parent
 * domain.** A deployment serving signup at `signup.xivi.app` puts its customers
 * at `acme.xivi.app`. A single-label host — `localhost`, a container name — has
 * no parent to take and keeps itself, so a fresh checkout gets `acme.localhost`,
 * which is exactly right in development.
 *
 * ### The promise and the fact are one function on purpose
 *
 * [XIV-65]'s form shows the visitor what their address will be, beside the box
 * they are typing the name into, and {@see SignupPage::tenantDomain()} is what
 * it shows. That method's own docblock called itself *"a display hint"* and
 * said, in as many words, that what a confirmed signup is finally routed at was
 * [XIV-98]'s to decide. This class is that decision, and the page now delegates
 * to it rather than computing an answer of its own.
 *
 * That direction is the load-bearing part. Two implementations of "the domain a
 * customer sits under" is two implementations of a promise, and the way anybody
 * would find out they had drifted is a customer typing the address they were
 * shown into a browser and reaching nothing. One function, consumed by the page
 * that promises and by the provisioner that delivers, cannot drift.
 *
 * ### Why the reservation rules already line up with this
 *
 * §8.12 reserves the **first label** of every system host rather than the whole
 * string, and the reason it gives is precisely this composition: a control plane
 * at `control.xivi.app` is collided with by a signup for `control`, because that
 * signup would be routed at `control.xivi.app`. That paragraph was written
 * against the convention this class now implements; it becomes correct rather
 * than merely well-aimed the moment the convention is code.
 *
 * A hostname built here is still checked twice more before it exists —
 * {@see \Xivi\ControlPlane\Signup\SignupIntake} refuses one another tenant
 * already owns at the moment somebody asks for the name, and `provision()`
 * refuses a system host and a taken host again when it runs. Neither check is
 * redundant: the first is what stops a customer being promised something, and
 * the second is what stops a promise being kept wrongly.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SelfServiceTenantHostname
{
    public function __construct(
        private SignupHost $host,
    ) {
    }

    /**
     * The domain a self-service customer's own address sits under.
     *
     * Empty when signup is switched off, which is the same "empty means off"
     * {@see SignupHost} uses throughout: there is no signup host, so there is no
     * parent domain, so there is nothing this could honestly answer. Callers
     * treat an empty answer as a refusal rather than composing `acme.` out of
     * it.
     */
    public function domain(): string
    {
        $host = $this->host->normalisedHost();
        $parent = strstr($host, '.');

        return $parent === false || $parent === '.' ? $host : substr($parent, 1);
    }

    /**
     * The hostname a tenant provisioned from this signup slug is routed at, or
     * an empty string when there is no domain to put it under.
     *
     * Normalised through {@see TenantResolver::normalize()}, the same function
     * tenancy resolves an incoming `Host` header with — so what is written into
     * `tenant_domain.hostname` is byte-for-byte what a request will be matched
     * against. `provision()` normalises again on the way in, which is one
     * normalisation too many and is left alone: the alternative is a caller that
     * has to remember, and this one costs nothing.
     */
    public function forSignupSlug(string $signupSlug): string
    {
        $domain = $this->domain();

        return $domain === '' ? '' : TenantResolver::normalize($signupSlug . '.' . $domain);
    }
}

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

namespace Xivi\ControlPlane\Signup;

use App\Tenancy\TenantResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Xivi\ControlPlane\Security\ControlPlaneHost;

/**
 * Where the signup endpoint is served, and the firewall that is deliberately not
 * there (XIV-64).
 *
 * The sibling of {@see ControlPlaneHost}, and it compares normalised strings for
 * the same reason that one does: `security.yaml`'s `host:` key is a *regular
 * expression*, so a hostname written into one is a pattern in which every dot
 * matches any character — `signup.example.com` would also accept
 * `signupXexample.com`, a name somebody else can own. One normalisation,
 * `TenantResolver::normalize()`, shared with tenancy, so this matches exactly the
 * host on which no tenant resolves.
 *
 * ### Its own hostname, rather than the control plane's
 *
 * The endpoint could have been a public path under `/control/`. It is not, and
 * the reason is §8.9's own advice about `CONTROL_PLANE_HOST`: *"ideally one that
 * is not guessable from the customer-facing domain"*. A hostname that has to be
 * configured into a third party's marketing site is a hostname that is written
 * down in somebody else's deployment, pasted into somebody else's chat, and
 * eventually in somebody else's repository. Serving an anonymous endpoint there
 * would spend the obscurity of the operator console to save one environment
 * variable, and it would aim the internet's traffic at the host that answers to
 * the people who can see every customer.
 *
 * ### The firewall here is `security: false`, which is a decision rather than an
 * omission
 *
 * `main` has no host restriction, so without an entry in `security.yaml` a
 * request here would land in the firewall whose provider is `tenant_users` —
 * looking people up in whichever customer's database the hostname resolved to,
 * on a host where none does. Nothing would come of it in practice, because the
 * endpoint carries no session and no credential the provider would be asked
 * about. "Nothing would come of it in practice" is the wrong standard for a
 * boundary, so the host gets a firewall of its own that runs no authentication
 * machinery at all: no provider, no session, nothing to hand a stray cookie to.
 *
 * The endpoint is not unauthenticated as a result — {@see SignupApiKey} is what
 * checks the shared secret, in the controller, in constant time. A Symfony
 * authenticator was the alternative and was rejected: `access_token` wants a
 * token handler that produces a `UserBadge` and a provider to resolve it
 * against, which means inventing a user for a caller that is a *deployment*
 * rather than a person, and a fourth firewall with a provider and access rules is
 * a great deal more `security.yaml` for one `hash_equals`.
 *
 * **It is declared below the control-plane firewall on purpose.** The two hosts
 * must differ and the route loader refuses to start if they do not — but if that
 * refusal is ever removed or worked around, the ordering decides which way the
 * mistake falls. Control plane first means a misconfigured deployment gets an
 * operator console that still demands a password; the other order means an
 * operator console with `security: false` in front of it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupHost implements RequestMatcherInterface
{
    public function __construct(
        #[Autowire('%app.signup_host%')]
        private string $host = '',
    ) {
    }

    /**
     * Matches on host only, and never at all when signup is switched off.
     *
     * The empty check is what makes "off" safe here as well as at the router: an
     * empty configured host would otherwise normalise to an empty string, and
     * although no request can have an empty `Host`, a firewall whose matcher is
     * one typo away from matching everything is not one to leave to that.
     */
    public function matches(Request $request): bool
    {
        if ($this->host === '') {
            return false;
        }

        return TenantResolver::normalize($request->getHost()) === $this->normalisedHost();
    }

    /** Empty when the endpoint is switched off. */
    public function normalisedHost(): string
    {
        return TenantResolver::normalize($this->host);
    }

    public function isEnabled(): bool
    {
        return $this->normalisedHost() !== '';
    }
}

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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The credential the calling site holds, and the only thing standing between the
 * open internet and this endpoint (XIV-64).
 *
 * ### A shared secret in a header, held by a server
 *
 * [XIV-65] can draw its form as a page that posts here directly from the
 * visitor's browser, or as a page whose own server posts here on the visitor's
 * behalf. **The second is what this is designed for**, and the difference is
 * where the credential lives: in the first design it is in the page's source,
 * which is to say in everybody's hands, and the endpoint additionally has to
 * appear on a public CORS origin list. In the second it is an environment
 * variable on one server, the endpoint is never called by a browser, and there
 * is no CORS to configure because there are no cross-origin requests to permit.
 *
 * There is deliberately no CORS configuration anywhere in this feature. That is
 * not an oversight to be filled in later: adding it is the change that turns the
 * first design from impossible into merely inadvisable.
 *
 * ### Compared in constant time, and refused when unset
 *
 * `hash_equals` because a `===` on a secret is a timing oracle — a slow one, and
 * one an attacker gets to sample as often as the rate limiter allows.
 *
 * **An unconfigured secret refuses everybody rather than admitting everybody**,
 * which is the one thing in this class that could plausibly have been written
 * the other way and must not be. A deployment that has set `SIGNUP_HOST` and
 * forgotten `XIVI_SIGNUP_SECRET` has published an anonymous endpoint; failing
 * closed makes that a feature that does not work, which somebody notices in
 * minutes, rather than a feature that works for everybody, which nobody notices
 * at all.
 *
 * ### What it is not
 *
 * It is not an identity. There is one secret and no notion of *which* caller
 * presented it, because there is one caller: the site this installation's
 * marketing pages are served from. When there are two — a partner's site, a
 * reseller — that is the moment for a table of keys with a name against each,
 * and the moment is not now. Rotating this one is editing one environment
 * variable in two places.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupApiKey
{
    /**
     * Where the secret is presented.
     *
     * A header of our own rather than `Authorization: Bearer`, because this is
     * not a bearer token in the OAuth sense and naming it as one invites a proxy,
     * a logger or a client library to treat it as one. Headers with an `X-`
     * prefix are also the ones reverse proxies most reliably pass through
     * untouched, which matters for a value the endpoint refuses without.
     */
    public const string HEADER = 'X-Xivi-Signup-Key';

    public function __construct(
        #[Autowire('%env(XIVI_SIGNUP_SECRET)%')]
        private string $secret = '',
    ) {
    }

    /**
     * @throws SignupRefused when the header is missing, empty, or wrong — one
     *                       answer for all three, because telling a caller *which*
     *                       is telling them something they did not already know
     */
    public function assertPresented(Request $request): void
    {
        if (!$this->isConfigured()) {
            throw SignupRefused::unauthorized('this installation has no signup secret configured');
        }

        $presented = $request->headers->get(self::HEADER, '');

        if (!hash_equals($this->secret, $presented)) {
            throw SignupRefused::unauthorized('the shared secret is missing or wrong');
        }
    }

    /**
     * Puts the secret on a request this installation is about to make (XIV-65).
     *
     * **The other half of `assertPresented()`, and it lives here so that the
     * secret has exactly one reader.** [XIV-65]'s landing page is a caller of the
     * intake like any other — it holds the credential and posts server-side, which
     * is the integration this class was designed for — so something had to be able
     * to *send* the value. The alternative was to autowire
     * `%env(XIVI_SIGNUP_SECRET)%` a second time, into the client. That would have
     * been two classes holding a secret in a private property, two places to grep
     * when it is rotated, and two places for one of them to start logging it. One
     * class, two verbs.
     *
     * It is a `Request` rather than an array of headers because the caller is
     * building a request object anyway ({@see SignupClient}) — and because handing
     * back the string, however briefly, is the shape that ends up interpolated
     * into a log line by somebody debugging.
     *
     * Silent when nothing is configured, which is not a failure to fail closed:
     * the endpoint refuses an absent header, so a page built with no secret gets
     * `unauthorized` from the intake and says so. The loud version of that refusal
     * is in {@see \Xivi\ControlPlane\Routing\SignupRouteLoader}, which will not
     * build a routing table at all for a host with no secret, so this situation
     * does not arise in a deployment that started.
     */
    public function presentOn(Request $request): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $request->headers->set(self::HEADER, $this->secret);
    }

    /** Whether a secret has been configured at all; see the class docblock for why this fails closed. */
    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }
}

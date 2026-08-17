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
use Xivi\ControlPlane\Routing\SignupRouteLoader;

/**
 * Whether this installation draws its own signup page (XIV-65).
 *
 * ### Three states, and this is only one of the two switches
 *
 * The page and the endpoint are wanted independently, and the three states a
 * deployment actually asks for are:
 *
 *   * **page and endpoint** — the default when signup is on at all, and what the
 *     company selling this runs. One hostname, a landing page on `/`, the intake
 *     under `/api/signup/v1/`.
 *   * **endpoint only** — somebody has built their own site and posts to the
 *     published contract. The built-in page would be a second front door onto the
 *     same intake, worse than theirs and confusing to find, so it is switched off
 *     and the contract is all that is served.
 *   * **neither** — a single company self-hosting. An open endpoint that records
 *     signups is a liability to them rather than a feature, and this is the
 *     shipped default: `.env` leaves `SIGNUP_HOST` empty.
 *
 * ### The two switches compose rather than duplicate
 *
 * {@see SignupHost} is [XIV-64]'s and answers *whether there is an intake, and
 * where*. This one answers *whether we also draw the form*. They are combined in
 * exactly one place — {@see isEnabled()} — and the combination is an `and`:
 *
 *     SIGNUP_HOST empty            → neither, whatever SIGNUP_PAGE says
 *     SIGNUP_HOST set, page false  → endpoint only
 *     SIGNUP_HOST set, page true   → page and endpoint
 *
 * That leaves the fourth combination — a page with no intake behind it —
 * **impossible to express** rather than refused by a check. It is worth being
 * deliberate about, because it is the one that would fail worst: a form that
 * renders, accepts a company name, and then cannot post anywhere is a page that
 * looks like it works to everybody except the person filling it in. The page is
 * this installation's *own caller* of the intake ({@see SignupClient}), so
 * "there is no intake" and "there is nothing for the page to call" are the same
 * sentence, and the `and` says it once.
 *
 * The consequence for [XIV-64]'s acceptance criterion is the good one: switching
 * both off still means **no route is registered**, because the page's routes are
 * loaded by the same {@see SignupRouteLoader} that returns an empty collection
 * when the host is empty. There is no second loader, no second `host:`, and no
 * second place for "is signup on" to be answered differently.
 *
 * ### Why the page shares the endpoint's hostname
 *
 * It could have had one of its own. It does not, for three reasons and one
 * non-reason.
 *
 * §8.12 argues at length that the *endpoint* must not be served on the
 * control-plane host, because a hostname configured into a third party's site
 * ends up in somebody else's repository and the operator console's should not.
 * That argument is about secrecy, and the page has none to lose: it is anonymous,
 * public and meant to be linked to. So it does not inherit the objection, and it
 * does not generate a new one — `SIGNUP_HOST` is already a system host that
 * resolves no tenant (§8.12), already carries a firewall with `security: false`,
 * and already has a certificate.
 *
 * The confirmation link is the argument that actually decides it. It lands on
 * `SIGNUP_HOST/signup/confirm/…` because only this side can answer it, and a
 * visitor who filled in a form at one name and is asked to confirm at another has
 * been handed the exact shape of a phishing mail. One name from the form to the
 * mailbox and back.
 *
 * The third is that a second hostname is a second variable, a second DNS record
 * and a second certificate for a page whose whole job is to post to the first
 * one. A deployment that genuinely wants the form under its marketing domain has
 * a better tool than a variable here: it puts its own page there and posts to the
 * contract, which is the "endpoint only" state above and is what that state is
 * for.
 *
 * The non-reason is path collision, which there is none of: the page takes `/`
 * and `/signup`, the intake takes `/api/signup/v1/…`, and the confirmation page
 * took `/signup/confirm/{token}` before either.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupPage
{
    public function __construct(
        private SignupHost $host,
        /**
         * `SIGNUP_PAGE`, defaulting to on.
         *
         * A boolean beside a hostname rather than a second hostname, which is the
         * opposite of the choice [XIV-64] made for the endpoint — and the reason
         * the two differ is that the endpoint's variable had a second job. It has
         * to say *where*, so a flag beside it would have been two facts that can
         * disagree ("enabled, but nobody said where"). This one has no second job:
         * where the page is served is already decided, by the host it shares, so
         * there is nothing for a hostname here to carry that is not already
         * carried.
         *
         * On by default because the deployment that has just set `SIGNUP_HOST` and
         * a secret has said it wants self-service, and the ordinary way to want
         * self-service is to want somewhere to send people. Somebody who has built
         * their own front end has, by definition, done something deliberate enough
         * to also set one variable.
         */
        #[Autowire('%app.signup_page%')]
        private bool $enabled = true,
    ) {
    }

    /**
     * Whether the landing page and its form exist at all.
     *
     * The `and` is the whole class; see the docblock above for why the fourth
     * state is not expressible and why that is the point rather than a
     * simplification.
     */
    public function isEnabled(): bool
    {
        return $this->host->isEnabled() && $this->enabled;
    }

    /**
     * The domain a customer's own address will sit under, for the form to show
     * beside the name box.
     *
     * The signup host without its first label: a deployment serving signup at
     * `signup.xivi.app` puts its customers at `acme.xivi.app`, and §8.12 relies on
     * the same relationship in the other direction when it reserves the *first
     * label* of every system host — `control` under the same domain is what a
     * control plane at `control.xivi.app` is collided with.
     *
     * **It is a display hint and the code says so out loud**, because it is the
     * one thing on this page that is not yet a fact. `TenantProvisioner` never
     * derives a hostname from a slug; hostnames are an explicit parameter, so what
     * a confirmed signup is finally routed at is [XIV-98]'s to decide. This is the
     * convention that decision will follow and the best answer available before it
     * has run — and it is shown because "you will be called acme" without saying
     * where is not showing somebody their address at all.
     *
     * A single-label host — `localhost`, a container name — has no parent to take,
     * and keeps itself: `acme.localhost` is exactly right in development and is
     * what a fresh checkout sees.
     */
    public function tenantDomain(): string
    {
        $host = $this->host->normalisedHost();
        $parent = strstr($host, '.');

        return $parent === false || $parent === '.' ? $host : substr($parent, 1);
    }
}

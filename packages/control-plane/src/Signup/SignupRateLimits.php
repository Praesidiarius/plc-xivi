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
use Symfony\Component\RateLimiter\CompoundLimiter;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * How often anybody may ask (XIV-64).
 *
 * Confirmation and one-signup-per-confirmed-address are what make *squatting*
 * expensive; neither of them makes *volume* expensive, and volume is its own
 * problem — a script posting a thousand addresses a minute costs this
 * installation a thousand outbound mails to people who did not ask for them,
 * which is a way of using us to send somebody else's spam. So there is a
 * limiter, and `symfony/rate-limiter` is it rather than something hand-rolled:
 * a sliding window done wrong is a limiter that resets on the hour and lets
 * everything through at 12:00:01.
 *
 * ### Three buckets, and what each is actually for
 *
 * **By address.** The narrow one. It bounds how many confirmation mails one
 * mailbox can be made to receive, which is the harm a stranger can aim at a
 * *person* using this endpoint. Small, because the only legitimate reason to
 * submit the same address repeatedly is "that mail did not arrive", and nobody
 * needs six goes at that in an hour.
 *
 * **By caller-supplied client address.** The broad one, and the reason the
 * number is not small: [XIV-65]'s recommended integration is a server-side post,
 * so an office of forty people behind one NAT is forty visitors sharing one
 * address, and a limit tuned for one person would lock out a real customer. See
 * {@see SignupSubmission::$clientIp} for why a value the caller supplies is
 * believed at all — the short version is that the caller holds the shared
 * secret, and the answer to a caller that lies is to rotate the secret.
 *
 * **By client address, for availability checks.** Its own bucket and a much
 * larger one, because a form that checks a name as somebody types is making a
 * request per keystroke-ish, and it writes nothing and sends nothing when it
 * does. Sharing the submission bucket would mean a visitor who thought about
 * their company name for a while could not then sign up.
 *
 * ### What this deliberately does not have
 *
 * **A global cap.** With a server-side integration every request arrives from
 * one transport address and the client address is supplied by the caller, so a
 * compromised or buggy caller can spread itself across as many buckets as it
 * likes and the per-address bucket is the only thing that bounds it. A ceiling
 * on the endpoint as a whole would bound that too — and it would also be a
 * single number that one busy afternoon turns into an outage for everybody. It
 * is named here as a known gap rather than left to be discovered: the thing that
 * actually answers a compromised caller is rotating the secret.
 *
 * ### Storage
 *
 * A cache pool, which is the component's own default arrangement. On this
 * runtime — FrankenPHP in classic mode, one instance (§9.2) — the filesystem
 * adapter is a real shared store between requests, and there is nothing to
 * supervise. **A deployment that ever runs two instances needs a shared pool**,
 * or each instance enforces its own copy of the limit; that is a line in
 * `config/packages/rate_limiter.yaml` rather than a change here.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupRateLimits
{
    public function __construct(
        #[Autowire(service: 'limiter.signup_address')]
        private RateLimiterFactoryInterface $byAddress,
        #[Autowire(service: 'limiter.signup_client')]
        private RateLimiterFactoryInterface $byClient,
        #[Autowire(service: 'limiter.signup_slug_check')]
        private RateLimiterFactoryInterface $slugChecks,
    ) {
    }

    /**
     * One submission's worth of quota, from the address bucket and the client
     * bucket together.
     *
     * `CompoundLimiter` rather than two `consume()` calls and a comparison,
     * because getting that right by hand means deciding what to do when the first
     * succeeds and the second refuses — and the component has already decided:
     * it consumes from every limiter and reports the most restrictive answer. The
     * consequence is that a caller who is already over the client limit also
     * burns address quota, which is the correct trade in this direction: the
     * address bucket exists to protect a *mailbox*, and somebody hammering the
     * endpoint is exactly who it should be protected from.
     *
     * @throws SignupRefused with {@see SignupError::RateLimited} and a wait
     */
    public function consumeForSubmission(string $email, string $clientAddress): void
    {
        self::enforce(new CompoundLimiter([
            $this->byAddress->create($email),
            $this->byClient->create($clientAddress),
        ])->consume());
    }

    /**
     * @throws SignupRefused with {@see SignupError::RateLimited} and a wait
     */
    public function consumeForSlugCheck(string $clientAddress): void
    {
        self::enforce($this->slugChecks->create($clientAddress)->consume());
    }

    /**
     * Whose quota this request spends.
     *
     * The caller's forwarded address where there is one, and the transport
     * address otherwise — which is what a caller posting directly, or anything
     * calling this by hand, will produce. `Request::getClientIp()` rather than
     * `REMOTE_ADDR` so that a deployment behind a trusted proxy gets the right
     * answer, and the empty string rather than null so that the key is always a
     * string: a null key would silently become one shared bucket.
     */
    public static function clientAddress(Request $request, string $forwarded = ''): string
    {
        return $forwarded !== '' ? $forwarded : ($request->getClientIp() ?? '');
    }

    /**
     * @throws SignupRefused
     */
    private static function enforce(RateLimit $limit): void
    {
        if ($limit->isAccepted()) {
            return;
        }

        // Rounded up and never below one: a `Retry-After: 0` reads as "try again
        // immediately", which is the opposite of what has just been said.
        $wait = max(1, (int) ceil($limit->getRetryAfter()->getTimestamp() - microtime(true)));

        throw SignupRefused::rateLimited($wait);
    }
}

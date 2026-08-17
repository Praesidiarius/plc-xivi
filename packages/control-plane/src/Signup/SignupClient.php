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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Xivi\ControlPlane\Controller\SignupApiController;
use Xivi\ControlPlane\Controller\SignupPageController;

/**
 * The landing page's own caller of the public intake (XIV-65).
 *
 * ### Why the page does not simply call `SignupIntake`
 *
 * It is in the same process. One `->record($submission)` would work, would be
 * faster, and is wrong for two reasons that outlive the convenience.
 *
 * **The secret is the design.** §8.12 says the recommended integration is a
 * server-side post carrying `X-Xivi-Signup-Key`, and it says so because the
 * alternative — a browser posting to the intake — puts the credential in the
 * page's source and forces a CORS origin list onto an anonymous endpoint. The
 * page this installation ships is the *reference* implementation of that
 * integration; a version of it that reached past the contract into the service
 * would be recommending one thing and doing another, and the first person to copy
 * it would copy the wrong half.
 *
 * **The contract stops being exercised.** `/api/signup/v1/requests` is an
 * interface somebody else compiles against ({@see SignupApiController}). If the
 * only thing that ever speaks it is a test, then the shape, the header name, the
 * status codes and the error vocabulary are proven by a fixture. If the company
 * selling this runs a page that goes through the front door, they are broken by
 * the same change that breaks their customers' integrations, in their own
 * staging, before anybody else is.
 *
 * ### The request is real; the socket is not
 *
 * What crosses is a genuine `Request` — `POST`, `Content-Type: application/json`,
 * the documented body, the secret in {@see SignupApiKey::HEADER} — routed by the
 * router to the real controller, which parses it with the real
 * {@see SignupSubmission::fromPayload()}, checks the real secret, spends the real
 * rate limiter and writes to the real database. The response is parsed back out
 * of JSON exactly as a third party would parse it. **Every part of the published
 * contract is therefore proven rather than assumed.** What is *not* proven is DNS,
 * TLS, and whatever proxy a deployment puts in front — and that is stated plainly
 * rather than glossed, because those are real parts of an external caller's
 * experience and this does not cover them.
 *
 * **A real socket was the alternative and it was rejected**, on two grounds:
 *
 *   * **Self-deadlock at saturation.** FrankenPHP runs in classic mode here
 *     (§9.2), so a request occupies a worker. A page that opens an HTTP
 *     connection back to its own server holds one worker while waiting for a
 *     second. With *n* workers, *n* simultaneous submissions each hold one and
 *     each wait for one that will never come free. That is not a slow page under
 *     load, it is a stopped instance, and it stops precisely on the busiest day.
 *   * **The instance would have to resolve and trust its own public name.** The
 *     route is `https` and bound to `SIGNUP_HOST`, so the connection has to be
 *     made to that hostname with a certificate that validates — from inside a
 *     container that, behind a load balancer doing TLS termination or split-horizon
 *     DNS, frequently cannot. The failure is a landing page that works everywhere
 *     except production.
 *
 * A third option, a second HTTP client pointed at `127.0.0.1` with the `Host`
 * header set by hand, buys back neither the DNS nor the TLS it skips, and still
 * deadlocks. There was nothing left in the socket worth paying for.
 *
 * ### What it means for the page's own safety
 *
 * The page is an **authenticated caller wrapping an anonymous one**, which is the
 * shape to be careful about: whoever can reach the page's routes can cause the
 * intake to be called with this installation's credential. That is inherent to
 * having a signup form at all — the form is *for* strangers — and it is why the
 * page's routes are bound to the signup host and switched off with the page
 * ({@see SignupPage}), why the visitor's own address is forwarded so the rate
 * limiter buckets per visitor rather than per installation, and why nothing here
 * is reachable from a component endpoint that any host serves. See
 * {@see SignupPageController} for the whole argument, including the one thing this
 * arrangement genuinely gives away.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupClient
{
    public function __construct(
        private HttpKernelInterface $kernel,
        private SignupApiKey $apiKey,
        private SignupHost $host,
    ) {
    }

    /**
     * What this company would be called, and whether the name is free.
     *
     * `$slug` empty means "derive it", which is the ordinary case while somebody
     * is still typing their company name; a non-empty one is the name they have
     * edited it to, and is checked rather than derived.
     */
    public function checkName(string $companyName, string $slug, string $clientIp): SlugAvailability
    {
        [$status, $body] = $this->call('/api/signup/v1/slug', [
            'company' => $companyName,
            'slug' => $slug,
            'client_ip' => $clientIp,
        ]);

        if ($status !== Response::HTTP_OK) {
            // A refusal from the availability call is still an answer about a
            // name: `invalid_slug` for something that cannot be a hostname label,
            // `rate_limited` for somebody typing very fast indeed. The slug is
            // echoed back from what was asked, because a refused derivation has
            // none of its own.
            return SlugAvailability::refused($slug, self::errorIn($body) ?? SignupError::InvalidRequest);
        }

        $derived = \is_string($body['slug'] ?? null) ? $body['slug'] : $slug;

        return ($body['available'] ?? false) === true
            ? SlugAvailability::free($derived)
            : SlugAvailability::refused($derived, self::reasonIn($body) ?? SignupError::SlugTaken);
    }

    /**
     * Record the signup, which sends the confirmation mail and creates nothing.
     *
     * The locale travels because there is nowhere else the intake could get it:
     * the person has no account on this installation, and the `Accept-Language` of
     * a server-to-server post is the calling server's. Here the calling server is
     * us, so it is passed explicitly from the request the visitor actually made
     * rather than left to be inferred from one we made ourselves.
     */
    public function submit(
        string $email,
        string $companyName,
        string $slug,
        string $plan,
        string $locale,
        string $clientIp,
    ): SignupOutcome {
        [$status, $body, $retryAfter] = $this->call('/api/signup/v1/requests', [
            'email' => $email,
            'company' => $companyName,
            'slug' => $slug,
            'plan' => $plan,
            'locale' => $locale,
            'client_ip' => $clientIp,
        ]);

        if ($status !== Response::HTTP_CREATED) {
            return SignupOutcome::refused(
                self::errorIn($body) ?? SignupError::InvalidRequest,
                $retryAfter,
            );
        }

        return SignupOutcome::accepted(
            \is_string($body['slug'] ?? null) ? $body['slug'] : $slug,
            \is_string($body['email'] ?? null) ? $body['email'] : $email,
            self::momentIn($body['confirmation_expires_at'] ?? null),
        );
    }

    /**
     * One call to the published contract.
     *
     * @param array<string, string> $body
     *
     * @return array{int, array<string, mixed>, ?int} status, decoded body, `Retry-After`
     */
    private function call(string $path, array $body): array
    {
        $request = Request::create(
            // Absolute, with the scheme, because both are part of what is being
            // matched: the routes are stamped with the signup host and forced to
            // `https` by the loader, so a request built without either would not
            // route — which is the correct outcome and a confusing one to debug,
            // so it is simply built correctly.
            sprintf('https://%s%s', $this->host->normalisedHost(), $path),
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        $this->apiKey->presentOn($request);

        // `catch: false`, deliberately. The intake answers every refusal it knows
        // about with a JSON body and a status, so anything that reaches this as an
        // exception is a defect rather than a refusal — and turning a defect into
        // a rendered error message is how a landing page comes to say "that name
        // is taken" about a database that is down.
        $response = $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST, false);

        $decoded = json_decode((string) $response->getContent(), true);

        $retryAfter = $response->headers->get('Retry-After');

        return [
            $response->getStatusCode(),
            \is_array($decoded) ? $decoded : [],
            ctype_digit((string) $retryAfter) ? (int) $retryAfter : null,
        ];
    }

    /**
     * The published error code in a refusal, or null when the body is not one.
     *
     * Unknown codes come back as null rather than as a guess, which is the
     * versioning rule read from the caller's side: §8.12 permits a case to be
     * *added* within v1 precisely so that an older caller can fall back, and
     * falling back is what null is for here.
     *
     * @param array<string, mixed> $body
     */
    private static function errorIn(array $body): ?SignupError
    {
        return \is_string($body['error'] ?? null) ? SignupError::tryFrom($body['error']) : null;
    }

    /**
     * The same, for the `reason` an availability answer carries. A different key
     * for the same vocabulary, because one is a refusal of the request and the
     * other is a fact about a name.
     *
     * @param array<string, mixed> $body
     */
    private static function reasonIn(array $body): ?SignupError
    {
        return \is_string($body['reason'] ?? null) ? SignupError::tryFrom($body['reason']) : null;
    }

    /**
     * The expiry, which the contract writes in ATOM.
     *
     * Anything unparseable is null rather than an exception: the signup has been
     * recorded by the time this is read, and refusing to draw the success page
     * over a malformed timestamp would tell the visitor their signup failed when
     * it did not.
     */
    private static function momentIn(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

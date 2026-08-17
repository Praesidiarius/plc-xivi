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

namespace Xivi\ControlPlane\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Xivi\ControlPlane\Signup\SignupApiKey;
use Xivi\ControlPlane\Signup\SignupError;
use Xivi\ControlPlane\Signup\SignupIntake;
use Xivi\ControlPlane\Signup\SignupRateLimits;
use Xivi\ControlPlane\Signup\SignupRefused;
use Xivi\ControlPlane\Signup\SignupSubmission;

/**
 * The public signup API (XIV-64).
 *
 * **This is an interface somebody else compiles against**, which is [XIV-65]'s
 * doing: the landing page is a separate site with its own deployment, so what is
 * written below is a contract rather than a description of how one page happens
 * to talk to one action. It is documented here rather than only in the brief
 * because this is the file somebody changes.
 *
 * ---
 *
 * ### `POST /api/signup/v1/requests`
 *
 * Records a signup and sends its confirmation mail. **Creates no tenant, no
 * database and no role** — see {@see SignupIntake} for why that is the point of
 * the ticket rather than a limitation of it.
 *
 * Request headers: `Content-Type: application/json`, and the shared secret in
 * `X-Xivi-Signup-Key` ({@see SignupApiKey}).
 *
 * Request body — the fields are {@see SignupSubmission}'s and their rules are
 * documented there:
 *
 *     {
 *       "email":     "owner@acme.example",   // required
 *       "company":   "Acme AG",              // required
 *       "slug":      "acme",                 // optional, derived from company when absent
 *       "plan":      "standard",             // optional, first configured plan when absent
 *       "locale":    "de",                   // optional, the installation's default when absent
 *       "client_ip": "203.0.113.9"           // optional, the visitor's address as the caller saw it
 *     }
 *
 * `201 Created`:
 *
 *     {
 *       "status": "pending_confirmation",
 *       "slug": "acme",
 *       "email": "owner@acme.example",
 *       "plan": "standard",
 *       "confirmation_expires_at": "2026-08-18T14:02:00+00:00"
 *     }
 *
 * `"pending_confirmation"` is the only status this endpoint returns, and it is
 * returned as a field rather than left implicit in the 201 so that a caller can
 * branch on a value rather than on a status code it may not control (a proxy
 * between the two is entitled to rewrite a 201 to a 200).
 *
 * ### `POST /api/signup/v1/slug`
 *
 * Derives a name and says whether it is free. Writes nothing.
 *
 *     { "company": "Acme AG", "slug": "", "locale": "de", "client_ip": "203.0.113.9" }
 *
 * `200 OK`:
 *
 *     { "slug": "acme", "available": true }
 *     { "slug": "acme", "available": false, "reason": "slug_taken" }
 *
 * `available` is the answer and `reason` is present only when it is `false`. The
 * reason is a {@see SignupError} value, so a caller renders it with the same
 * table it renders a refused submission with.
 *
 * ### Errors
 *
 * Every refusal, from either action:
 *
 *     { "error": "slug_taken", "message": "…" }
 *
 * `error` is the stable vocabulary and is what a caller branches on; `message` is
 * one fixed English sentence per code and is **not** a string to show a visitor —
 * [XIV-65] owns the words its visitors read, in their language. It is fixed
 * rather than descriptive on purpose: the internal message says *why* a name is
 * unavailable, and saying that is what {@see SignupError::SlugTaken} exists to
 * avoid. The codes, their meanings and their HTTP statuses are
 * {@see SignupError}.
 *
 * ### Versioning
 *
 * The version is in the path. Within `v1`: request fields may be added and must
 * be optional; response fields may be added; {@see SignupError} cases may be
 * added. Nothing may be removed, renamed, or made required. Anything else is
 * `/api/signup/v2/`, served beside v1 rather than instead of it.
 *
 * ### Order of operations, which is a security property rather than a style
 *
 * The secret is checked **first**, before the body is parsed and before any
 * limiter is touched. If the limiter came first, anybody at all could exhaust a
 * chosen victim's address bucket without holding the credential — turning a
 * defence against abuse into an instrument of it. Parsing comes second because
 * the limiter is keyed on the address in the body, and the intake comes last
 * because it is the only step that writes anything.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
// `AsController` rather than extending `AbstractController`, which is what every
// other controller in this package does. This one renders nothing, redirects
// nowhere and needs no session, so the base class's whole service locator would
// be inherited for the sake of a JSON response — and the attribute is exactly
// what says "this is a controller" to autoconfiguration without it.
#[AsController]
#[Route('/api/signup/v1', name: 'signup_api_v1_')]
final readonly class SignupApiController
{
    public function __construct(
        private SignupApiKey $apiKey,
        private SignupRateLimits $limits,
        private SignupIntake $intake,
    ) {
    }

    #[Route('/requests', name: 'request', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        try {
            $this->apiKey->assertPresented($request);

            $submission = SignupSubmission::fromPayload(self::payload($request));

            $this->limits->consumeForSubmission(
                $submission->email,
                SignupRateLimits::clientAddress($request, $submission->clientIp),
            );

            $signup = $this->intake->record($submission);
        } catch (SignupRefused $refused) {
            return self::refusal($refused);
        }

        return self::json([
            'status' => 'pending_confirmation',
            'slug' => $signup->getSlug(),
            'email' => $signup->getEmail(),
            'plan' => $signup->getPlan(),
            // ATOM rather than a formatted local time: this is a machine reading
            // it, and the only unambiguous way to write a moment is with its
            // offset attached.
            'confirmation_expires_at' => $signup->getConfirmationExpiresAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/slug', name: 'slug', methods: ['POST'])]
    public function slug(Request $request): JsonResponse
    {
        try {
            $this->apiKey->assertPresented($request);

            $submission = SignupSubmission::fromPayload(self::payload($request));

            $this->limits->consumeForSlugCheck(
                SignupRateLimits::clientAddress($request, $submission->clientIp),
            );

            $availability = $this->intake->availability(
                $submission->slug,
                $submission->companyName,
                $submission->locale,
            );
        } catch (SignupRefused $refused) {
            return self::refusal($refused);
        }

        $body = ['slug' => $availability->slug, 'available' => $availability->isAvailable()];

        if ($availability->reason !== null) {
            $body['reason'] = $availability->reason->value;
        }

        return self::json($body);
    }

    /**
     * @throws SignupRefused when the body is not JSON at all
     */
    private static function payload(Request $request): mixed
    {
        try {
            return json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $malformed) {
            throw SignupRefused::invalidBody($malformed->getMessage());
        }
    }

    private static function refusal(SignupRefused $refused): JsonResponse
    {
        // **The enum's sentence, never the exception's.** `SignupRefused` builds a
        // detailed message for whoever is reading a stack trace, and several of
        // those messages say precisely what the vocabulary is designed not to
        // say — which of three reasons made a name unavailable, or which mail
        // server refused a message. Returning `getMessage()` here would have
        // undone {@see SignupError::SlugTaken}'s whole argument from inside the
        // response it was written for.
        $response = self::json(
            ['error' => $refused->error->value, 'message' => $refused->error->message()],
            $refused->error->statusCode(),
        );

        if ($refused->retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) $refused->retryAfterSeconds);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($body, $status);

        // Nothing here is cacheable and none of it belongs in a shared cache: an
        // availability answer changes the moment somebody confirms, and a
        // submission response names an address. Said out loud rather than left to
        // whatever a proxy in front of this decides on its own.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}

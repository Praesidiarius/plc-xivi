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

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Routing\SignupRouteLoader;
use Xivi\ControlPlane\Signup\SignupClient;
use Xivi\ControlPlane\Signup\SignupError;
use Xivi\ControlPlane\Signup\SignupIntake;
use Xivi\ControlPlane\Signup\SignupPage;

/**
 * The landing page and its signup form (XIV-65).
 *
 * ### A landing page, and the scope is the decision
 *
 * One page with one form on it. **Not a marketing site**: no pricing, no
 * features, no content model, nothing a non-engineer is expected to edit. That
 * was weighed and settled when the ticket was written, and the reason it was
 * settled that way is that a marketing site and an ERP have nothing in common
 * except this form — different release cadence, different authors, different
 * risk. The day somebody genuinely wants pages, the answer is not to grow this
 * one: it is a site of its own that posts to the published contract, which
 * §8.12 built the contract for and which the "endpoint only" state
 * ({@see SignupPage}) exists to serve.
 *
 * ### It goes through the front door
 *
 * Everything below calls {@see SignupClient}, which speaks
 * `/api/signup/v1/…` with the shared secret, rather than reaching into
 * {@see SignupIntake}. That class's docblock has the argument at length. The
 * consequence worth having in mind here is that **this controller has no
 * privileges of its own at all** — it renders, it forwards, and every decision
 * about what may be recorded is taken by the intake behind a credential.
 *
 * ### The secret is not in the page, and cannot get there
 *
 * The visitor's browser talks to these three routes and to nothing else. It never
 * learns the intake's hostname beyond the one it is already on, never sees
 * `X-Xivi-Signup-Key`, and never makes a cross-origin request — so there is no
 * CORS to add, which §8.12 is emphatic about: adding it is the change that makes
 * the browser-side design possible.
 *
 * Nothing in the templates or the JSON below carries the secret, and
 * `SignupPageTest` asserts that against the rendered bytes rather than against
 * this paragraph, because a paragraph does not fail a build.
 *
 * ### What this arrangement does give away, said plainly
 *
 * A page with a live name check **is** an availability oracle offered to
 * anonymous visitors. §8.12 names exactly this and asks the deployment to say so
 * to itself: `available: false` is one bit, and a script can walk it. The shared
 * secret and the rate limiter stop being the things that gate that bit the moment
 * a page in front of them is public — what is left is the per-visitor bucket in
 * `signup_slug_check`, which bounds a walker rather than preventing one, and the
 * fact that "unavailable" is one word for three situations
 * ({@see SignupError::SlugTaken}) so a walker cannot tell a customer from a
 * reserved word.
 *
 * That is the cost of showing somebody their address before they commit to it,
 * which is the entire point of the ticket. A deployment that will not pay it
 * switches the page off and keeps the endpoint, which is the second of the three
 * states and is one variable.
 *
 * The submission route is the same shape and a smaller worry: it is bounded by
 * the address bucket and the client bucket, it reserves nothing (§8.12), and a
 * name is held only by a confirmed mailbox — so a script that posts ten thousand
 * company names has produced ten thousand rows and blocked nobody.
 *
 * ### No CSRF token, and that is a decision rather than an omission
 *
 * The form posts without one. CSRF protection stops a third-party page from
 * spending a *credential the browser holds* — a session cookie, chiefly — and
 * there is none here to spend: the signup host has `security: false` (§8.12),
 * nothing on this page is authenticated, and a forged cross-site post can achieve
 * precisely what the forger could achieve by posting from their own server, which
 * is to make an unconfirmed row exist. The confirmation mail is what makes that
 * worth nothing, and it is unaffected.
 *
 * The one thing a forgery does buy is that the *victim's* address goes into the
 * client bucket rather than the attacker's. That is a rate-limiting nuisance and
 * not a security boundary, and paying for it with a token would mean starting a
 * session for every anonymous visitor to this page — state on the one host in the
 * system that has none.
 *
 * ### And no Live Component, which is the departure worth explaining
 *
 * XIV-33 adopted Symfony UX Live Components and this page is exactly their
 * shape — a form that re-renders as somebody types. It is not one, and the
 * reason is structural rather than stylistic.
 *
 * A live component is reachable at `/_components/{name}/{action}`, a route
 * registered once by the bundle for every host this installation serves. The
 * component is resolved from the *route parameter*, not from any route of its
 * own, so a `SignupForm` component would answer at that path **wherever it is
 * asked** — including on the signup host while the page is switched off. The
 * page switch would then switch off a template and leave its actions running,
 * which is the "hidden page" [XIV-64] wrote a whole route loader to avoid, and
 * this ticket's acceptance criterion repeats. There is no way to say "this
 * component only exists on this host, and only when a variable says so", because
 * the route that reaches it is not this feature's to configure.
 *
 * So the live half is a small Stimulus controller (`assets/controllers/`) posting
 * to {@see name()}, which is an ordinary route: stamped with the signup host,
 * forced to `https`, and absent from the routing table when either switch is off
 * ({@see SignupRouteLoader}). The page stays server-rendered — every byte of HTML
 * below is Twig, and the script sets text into three elements and nothing else,
 * which is the line `assets/app.js` draws and is worth keeping on the one page in
 * this repository that strangers see.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupPageController extends AbstractController
{
    public function __construct(
        private readonly SignupClient $signups,
        private readonly SignupIntake $intake,
        private readonly SignupPage $page,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The page itself.
     *
     * `/` rather than `/signup`, because on this hostname there is nothing else:
     * a visitor who trims a URL back to its root should find the thing the
     * hostname is for rather than a 404, and a deployment linking to it from a
     * mail signature wants the short form.
     */
    #[Route('/', name: 'signup_page', methods: ['GET'])]
    public function page(): Response
    {
        return $this->render('@XiviControlPlane/signup_page.html.twig', $this->view(self::emptyDraft()));
    }

    /**
     * What the name would be, and whether it is free — asked while somebody types.
     *
     * JSON rather than a rendered fragment, and this is the exception to
     * "server-rendered" that the class docblock argues for: what comes back is a
     * name, a yes-or-no and one already-translated sentence. Returning markup
     * would mean the script inserting HTML, which is a different and larger
     * promise on a page anonymous visitors reach.
     *
     * **`POST` for a call that writes nothing**, which looks wrong and is
     * deliberate: it is what the contract underneath uses
     * ({@see SignupApiController}), a company name is not a thing to put in a URL
     * where proxies and logs keep it, and the answer must not be cached by
     * anything — a name that has gone must not still read as free because a
     * browser remembered.
     */
    #[Route('/signup/name', name: 'signup_page_name', methods: ['POST'])]
    public function name(Request $request): JsonResponse
    {
        $availability = $this->signups->checkName(
            self::field($request, 'company'),
            self::field($request, 'slug'),
            self::visitorAddress($request),
        );

        return new JsonResponse([
            'slug' => $availability->slug,
            'available' => $availability->isAvailable(),
            'message' => $availability->reason !== null
                ? $this->sentenceFor($availability->reason)
                : $this->translator->trans('landing.name.free', ['%slug%' => $availability->slug], 'landing'),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * The form was submitted.
     *
     * Rendered in place rather than redirected, and the trade is worth stating.
     * Post-Redirect-Get would need somewhere to park the answer between the two
     * requests — a flash bag, and therefore a session, on the one host in this
     * system that deliberately has none. Rendering in place also keeps every field
     * a visitor typed in front of them when the name they wanted has gone, which
     * is the most likely refusal and the one where losing the form would be worst.
     *
     * The cost is that reloading the success page re-posts it. That is a *resend*
     * rather than a duplicate — §8.12 makes a second submission from an
     * unconfirmed address rewrite the row and mint a new link — and the address
     * bucket bounds it at five an hour.
     */
    #[Route('/signup', name: 'signup_page_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $draft = [
            'company' => self::field($request, 'company'),
            'slug' => self::field($request, 'slug'),
            'email' => self::field($request, 'email'),
            'plan' => self::field($request, 'plan'),
        ];

        $outcome = $this->signups->submit(
            $draft['email'],
            $draft['company'],
            $draft['slug'],
            $draft['plan'],
            // The language this visitor is reading *this page* in, which
            // `UserLocaleListener` negotiated from their browser because there is
            // no account here to have stored a preference on. It becomes the
            // language of their confirmation mail, and it is passed explicitly
            // rather than left to be inferred, because the request the intake
            // actually receives is one this installation made.
            $request->getLocale(),
            self::visitorAddress($request),
        );

        $response = $this->render(
            '@XiviControlPlane/signup_page.html.twig',
            $this->view($draft) + [
                'outcome' => $outcome,
                'error' => $outcome->error !== null
                    ? $this->sentenceFor($outcome->error, $outcome->retryAfterSeconds)
                    : null,
            ],
            // **The status is the intake's answer, restated.** A refused signup
            // that answers `200` is a page a monitor reads as healthy and a
            // crawler indexes as content; a 4xx is what actually happened, and it
            // is the same distinction the confirmation page draws one route along.
            new Response(status: $outcome->isAccepted() ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST),
        );

        // Nothing on this page is cacheable: it names an address and it answers a
        // question about a name that changes the moment somebody else confirms.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * The error vocabulary, in words a person can act on.
     *
     * §8.12 splits this deliberately: the endpoint answers with a **code** and one
     * fixed English sentence written for a developer's log, and this ticket owns
     * what a visitor reads, in their language. So there is exactly one `match`
     * over the published enum and one key per case in the `landing` catalogue —
     * and no `default`, so that a case added to {@see SignupError} within v1 fails
     * to compile here rather than showing somebody a blank.
     *
     * The three the acceptance criteria name are the three that carry the most
     * weight, and each is written as an instruction rather than a diagnosis:
     *
     *   * `slug_taken` — the name has gone, pick another; and pointedly *not* why,
     *     because the endpoint refuses to distinguish a customer from a reserved
     *     word and a page that guessed would undo that.
     *   * `address_already_registered` — this address already has an installation
     *     on the way, so the thing to do is wait for it or write to us, not try
     *     again.
     *   * `rate_limited` — too many attempts, and *for how long*, because a person
     *     told to stop and not told when either gives up or reloads immediately.
     */
    private function sentenceFor(SignupError $reason, ?int $retryAfterSeconds = null): string
    {
        $key = match ($reason) {
            SignupError::SlugTaken => 'landing.error.slug_taken',
            SignupError::AddressAlreadyRegistered => 'landing.error.address_already_registered',
            SignupError::RateLimited => 'landing.error.rate_limited',
            SignupError::InvalidEmail => 'landing.error.invalid_email',
            SignupError::InvalidSlug => 'landing.error.invalid_slug',
            SignupError::UnknownPlan => 'landing.error.unknown_plan',
            SignupError::MailFailed => 'landing.error.mail_failed',
            // Two the visitor cannot have caused and cannot act on, so they get
            // one honest sentence rather than an explanation of a header they
            // have never heard of. `unauthorized` in particular means *this
            // installation* is misconfigured — the page holds the wrong secret —
            // and telling a stranger that is neither useful to them nor wise.
            SignupError::Unauthorized, SignupError::InvalidRequest => 'landing.error.unavailable',
        };

        return $this->translator->trans(
            $key,
            ['%minutes%' => (int) ceil(($retryAfterSeconds ?? 0) / 60)],
            'landing',
        );
    }

    /**
     * The visitor's address, forwarded so that the intake's limiter buckets per
     * *visitor* rather than per installation.
     *
     * Without this every request the page makes arrives from one address — ours —
     * and the client bucket becomes a single counter for the whole internet, which
     * would either be so large as to bound nothing or so small as to be an outage.
     * {@see \Xivi\ControlPlane\Signup\SignupSubmission::$clientIp} is the field
     * that accepts it and explains why a caller holding the secret is believed
     * about it.
     *
     * `getClientIp()` rather than `REMOTE_ADDR`, so that a deployment behind a
     * trusted proxy forwards the visitor rather than the proxy.
     */
    private static function visitorAddress(Request $request): string
    {
        return $request->getClientIp() ?? '';
    }

    /** One posted field, trimmed, and never null — the form is plain HTML and may omit anything. */
    private static function field(Request $request, string $name): string
    {
        $value = $request->request->get($name, '');

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * Everything the template needs that is not about one submission.
     *
     * Collected here so that the two actions that render cannot pass different
     * sets — a refused submission that lost the plan list, or gained a domain the
     * first render did not have, is the sort of difference nobody sees until the
     * page it happens on is the one a stranger is looking at.
     *
     * @param array<string, string> $draft
     *
     * @return array<string, mixed>
     */
    private function view(array $draft): array
    {
        return [
            'draft' => $draft,
            'plans' => $this->intake->plans(),
            // Where a customer's own address will sit, which the form shows beside
            // the name box. A display hint rather than a promise — see
            // {@see SignupPage::tenantDomain()} for what is and is not decided yet.
            'domain' => $this->page->tenantDomain(),
            // From the intake's own constant, so the sentence on the success page
            // cannot drift from the window the token really has.
            'confirmation_hours' => self::confirmationHours(),
        ];
    }

    /** @return array<string, string> */
    private static function emptyDraft(): array
    {
        return ['company' => '', 'slug' => '', 'email' => '', 'plan' => ''];
    }

    /**
     * {@see SignupIntake::CONFIRMATION_WINDOW} in hours.
     *
     * Parsed from the interval rather than written out again, because "24" in two
     * places is one place that gets changed and one that does not — and the one
     * that does not is the sentence a visitor reads.
     */
    private static function confirmationHours(): int
    {
        $window = new \DateInterval(SignupIntake::CONFIRMATION_WINDOW);

        return $window->d * 24 + $window->h;
    }
}

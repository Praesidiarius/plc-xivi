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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Xivi\ControlPlane\Signup\ConfirmationOutcome;
use Xivi\ControlPlane\Signup\ConfirmationToken;
use Xivi\ControlPlane\Signup\SignupIntake;

/**
 * Where a confirmation link lands (XIV-64).
 *
 * ### This is not the page [XIV-65] owns
 *
 * That ticket owns the *form* — the marketing page a visitor fills in, on the
 * site that posts to {@see SignupApiController}. This is the other end of a link
 * in an email, and it has to exist here because it is the only place that can
 * answer it: the token is a row in the control-plane database, and the calling
 * site has no access to that and should not.
 *
 * So it is deliberately the plainest page in the repository. It says which of
 * the five things happened, in the language the mail was written in, and it does
 * not attempt to be a landing page. When [XIV-65]'s site wants the visitor
 * returned to it after confirming, that is a redirect target it configures, and
 * it is a small change to this action rather than a rewrite of it.
 *
 * ### The status codes are not decoration
 *
 * A confirmation URL is fetched by mail clients, link scanners and preview
 * bots as well as by people, and the status is the only part of the answer any
 * of them reads. `404` for a token that matches nothing, `410` for one whose
 * window closed — gone, and it used to be there — and `409` for the name having
 * been taken in the meantime. `200` covers both a confirmation and a repeat of
 * one, because a repeat is not an error: see {@see ConfirmationOutcome}.
 *
 * `X-Robots-Tag: noindex` on every one of them. The URL contains a secret, and a
 * search engine that has been handed one — by a browser extension, by a toolbar,
 * by a referrer — must not put it in an index.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupConfirmationController extends AbstractController
{
    public function __construct(
        private readonly SignupIntake $intake,
        private readonly LocaleSwitcher $locales,
    ) {
    }

    /**
     * The route requirement keeps a malformed token from ever reaching a query,
     * and keeps this from matching every stray path under `/signup/confirm/`.
     */
    #[Route(
        '/signup/confirm/{token}',
        name: 'signup_confirm',
        requirements: ['token' => ConfirmationToken::PATTERN],
        methods: ['GET'],
    )]
    public function confirm(string $token): Response
    {
        $confirmation = $this->intake->confirm($token);

        $status = match ($confirmation->outcome) {
            ConfirmationOutcome::Confirmed, ConfirmationOutcome::AlreadyConfirmed => Response::HTTP_OK,
            ConfirmationOutcome::Expired => Response::HTTP_GONE,
            ConfirmationOutcome::SlugTaken => Response::HTTP_CONFLICT,
            ConfirmationOutcome::Unknown => Response::HTTP_NOT_FOUND,
        };

        // In the language the mail was written in, which is the language this
        // person was reading the form in — they have no account here and no
        // session, so there is nothing else to go on. An unknown token has no
        // signup to ask, and falls back to whatever the request negotiated.
        $locale = $confirmation->signup?->getLocale();

        $render = fn (): Response => $this->render('@XiviControlPlane/signup_confirmation.html.twig', [
            'outcome' => $confirmation->outcome->name,
            'signup' => $confirmation->signup,
        ], new Response(status: $status));

        $response = $locale !== null ? $this->locales->runWithLocale($locale, $render) : $render();

        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}

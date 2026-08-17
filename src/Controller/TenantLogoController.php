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

namespace App\Controller;

use App\Tenancy\TenantContext;
use App\Tenant\Repository\TenantProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The customer's own mark, as bytes (XIV-49).
 *
 * **This is the one piece of a tenant's data readable without signing in, and it
 * is a deliberate exception rather than an oversight.** The ticket originally
 * asked for the mark to be served behind the same tenancy and permission rules as
 * the rest of the customer's data, and then asked for it on the sign-in page,
 * which are two requirements that cannot both hold: nobody on that page has a
 * session to check a permission against. Something had to give, and the
 * permission is the right half to give up — a logo is a public mark by
 * definition, printed on the customer's own letterhead, invoices and website,
 * and treating it as a secret would be protecting something they publish.
 *
 * **What is *not* given up is tenancy.** The route is scoped exactly as every
 * other request is: `TenantRequestListener` resolves the tenant from the Host
 * header at priority 100, before authentication runs, so this action can only
 * ever reach the profile of the host it was asked on. A system host resolves no
 * tenant at all and gets a 404 here rather than somebody else's mark.
 *
 * **And nothing else about the profile comes out of it.** The response body is
 * the image and the headers name its type and its length. The company name, the
 * currency, the payment terms and — the one that would actually matter — the
 * SMTP host and user sit on the very same row, which is precisely why this reads
 * three properties by name instead of handing a serialised profile to anything.
 * There is a test that says so.
 *
 * **The disclosure argument this used to lose on has already been settled
 * elsewhere.** Showing Acme's logo at `acme.xivi.app` was once objected to as
 * telling a visitor whose installation they had found; since XIV-79 the sign-in
 * card's `<h1>` *is* the hostname, so the heading above the mark already says it
 * in words. What is left is the thing the ticket wants: an installation that
 * reads as the customer's product from the first screen.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantLogoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantProfileRepository $profiles,
    ) {
    }

    /**
     * How long a browser may keep a mark it was given: a year.
     *
     * Absurd on its own and correct given the address. The fingerprint is in the
     * URL, so these bytes cannot change under this address — a new logo is a new
     * address, which the page starts pointing at the moment it is uploaded. The
     * lifetime is therefore chosen for how long it is worth *not* asking again
     * rather than for how stale the answer might get, and a year is the
     * conventional ceiling for exactly this arrangement.
     */
    private const int A_YEAR = 31_536_000;

    /**
     * The fingerprint in the path rather than in a query string.
     *
     * A query string is the usual way to bust a cache and is the weaker one:
     * caches and CDNs are entitled to ignore it when deciding what a URL means,
     * and some do. A path segment is part of the identity of the resource
     * everywhere, which is what this needs it to be.
     */
    #[Route('/logo/{fingerprint}', name: 'tenant_logo', requirements: ['fingerprint' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function show(string $fingerprint): Response
    {
        if (!$this->context->hasTenant()) {
            // A system host — the control plane's own login, a health check —
            // resolves no tenant, so there is no mark to ask for. 404 rather than
            // the instance's own logo: this route is about the customer's file,
            // and what a page without a tenant falls back to is the page's
            // decision, made in the template (XIV-48).
            throw $this->createNotFoundException();
        }

        $profile = $this->profiles->current();
        $bytes = $profile->getLogo();

        if ($bytes === null || $profile->getLogoContentType() === null) {
            throw $this->createNotFoundException();
        }

        $response = new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $profile->getLogoContentType(),
            // The stored type is the one decoded from the bytes on the way in, so
            // it is true — and this says a browser may not go looking for a
            // better one. Sniffing is how a file that is not what it claims gets
            // treated as what it looks like, which is the whole class of problem
            // LogoFormat's accepted list exists to keep out.
            'X-Content-Type-Options' => 'nosniff',
            // Belt and braces against the format decision being wrong one day:
            // nothing in an image needs to load anything, so nothing it asks for
            // is allowed. If a future ticket adds SVG behind a sanitizer, this
            // line is what makes the day the sanitizer misses something survivable.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // Named, so somebody who opens the address directly gets a file with
            // an extension rather than an untitled download. Inline because it is
            // an image on a page, not an attachment.
            'Content-Disposition' => sprintf('inline; filename="logo.%s"', $this->extension($profile->getLogoContentType())),
            // **The framework would otherwise overrule every cache header below,
            // and it would be right to.** Symfony's session listener stamps
            // `private, max-age=0, must-revalidate` on any response produced while
            // a session was started, because a page rendered for one signed-in
            // person must not be handed to the next — a default worth having and
            // worth being explicit about opting out of. This response is not that
            // page: it is the same bytes for everybody who asks, at an address
            // derived from the bytes, and it is the one route here that is
            // reachable with no session at all. So the opt-out is stated once, at
            // the top, where it is read next to the headers it protects rather
            // than discovered as a missing line further down. The listener strips
            // this header on the way out.
            AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
        ]);

        // A request for the wrong fingerprint is answered with the current mark
        // and told to keep nothing.
        //
        // This is the case where a stale page — one a browser or a proxy cached
        // before the logo changed — asks for an address that no longer names
        // anything. Serving the current bytes is the friendly answer and caching
        // them under the old address would be the bug: the address would then
        // hold two different images over its life, which is exactly the promise
        // `immutable` makes and breaks. So the guarantee is kept by refusing to
        // cache the one response that cannot honour it, and the next page load
        // asks under the right address and caches properly.
        if ($fingerprint !== $profile->getLogoFingerprint()) {
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        // Public rather than private, and that is the same claim the route
        // already makes by being reachable signed out: this is not somebody's
        // data, it is the customer's published mark, and a shared cache holding
        // it leaks nothing. Shared caches key on the whole URL, host included, so
        // two tenants cannot be served each other's from one.
        $response->setPublic();
        $response->setMaxAge(self::A_YEAR);
        $response->headers->addCacheControlDirective('immutable');
        // The fingerprint *is* the entity tag — it is a strong hash of exactly
        // the bytes being returned — so a client that revalidates anyway, which
        // a hard reload will, gets a 304 instead of half a megabyte.
        $response->setEtag($fingerprint);

        return $response;
    }

    /** For the download name only; the header above is what actually types it. */
    private function extension(string $contentType): string
    {
        return $contentType === 'image/jpeg' ? 'jpg' : 'png';
    }
}

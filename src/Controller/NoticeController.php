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

use App\Tenant\Entity\User;
use App\Tenant\Notice\NoticeInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Putting one of the operator's notices away (XIV-120, docs/architecture.md
 * §8.16).
 *
 * The only thing a customer can *do* about a notice, and the whole of the
 * feature's write side. It is a `POST` and a redirect rather than a Live
 * Component, and the line that decides which is §8.3.1's: a widget owns its own
 * controls when the control **narrows what is shown** — the follow-up lens — and
 * this one does not narrow anything, it writes a row that outlives the request.
 * A write that answers with a rendered page is a write somebody repeats by
 * pressing reload.
 *
 * **The write lands in the customer's own database**, which is not a choice this
 * controller makes: §4.4 gives the customer-facing instance no write privilege
 * anywhere in the control-plane database, so the dismissal goes where every other
 * write a customer's request makes goes. {@see \App\Tenant\Entity\NoticeDismissal}
 * has the argument. This is [XIV-102]'s shape and the mirror of the read that
 * brought the notice here in the first place.
 *
 * **No permission and nothing to grant.** Every user of an installation may put
 * away a notice they were shown, and only what they were shown: the inbox is
 * asked, per reader, whether the notice is live for *them*
 * ({@see NoticeInbox::dismiss()}), so authority is answered by the same query
 * that decided what to draw rather than by a second rule that could disagree with
 * it. There is no `@notices` permission area for the reason
 * {@see \App\Registry\Entity\NoticeAudience} gives: a customer able to configure
 * who reads announcements is a customer able to switch off the channel the
 * operator relies on.
 *
 * **No flash on the way back.** The card disappearing is the confirmation, and a
 * green bar saying "notice dismissed" on top of the space it just left would be
 * telling somebody what they can see.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NoticeController extends AbstractController
{
    /**
     * One token for the widget rather than one per notice.
     *
     * Every card on it is the same kind of thing, posted from the same page by
     * the same person; CSRF is about *this* browser having loaded *this* page,
     * not about which row on it was submitted — the pricing screen's argument,
     * one deployment over.
     */
    private const string CSRF = 'notice-dismiss';

    #[Route(
        '/notices/{id}/dismiss',
        name: 'notice_dismiss',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function dismiss(
        Request $request,
        int $id,
        NoticeInbox $inbox,
        #[CurrentUser] User $reader,
    ): Response {
        if ($this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            // The return value is deliberately ignored. "There was nothing to
            // dismiss" is what a page left open across a withdrawal produces, and
            // it wants the same answer as success: the dashboard, without that
            // card on it. Telling somebody that the thing they wanted gone was
            // already gone is noise about our own bookkeeping.
            $inbox->dismiss($id, $reader);
        }

        // A stale token gets the same redirect and no complaint, which is the
        // one place this differs from a form: there is nothing to re-enter and
        // nothing was lost. The next render shows the notice again, which is the
        // honest outcome of a request that could not be trusted.
        return $this->redirectToRoute('dashboard');
    }
}

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

use App\Tenant\Entity\SupportTicket;
use App\Tenant\Entity\User;
use App\Tenant\Support\SupportTickets;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a customer reaches whoever runs their installation (XIV-123,
 * docs/architecture/identity-and-access.md §8.17).
 *
 * The screen this whole ticket exists for. Before it there was **no channel from
 * a customer to the operator at all** — not a ticket, not a contact form, not an
 * address — so somebody whose invoice module was misbehaving had whatever email
 * address they happened to be given when they signed up, if they still had it.
 *
 * ## No permission, and that is decided rather than skipped
 *
 * Every signed-in user of an installation may raise one and may read what the
 * company has raised. §8.17 has the argument and {@see SupportTickets} carries
 * the short form: asking commits nothing, the person who met the problem is the
 * person who can describe it, and a permission here would be a switch whose only
 * effect is to silence somebody with a problem.
 *
 * **The firewall is the whole of the access control**, and it is real: a request
 * with no session does not reach this class. `SupportTicketTest` proves that
 * through the front door rather than by asking a guard, because a route that is
 * only protected by not being linked is not protected.
 *
 * ## POST and a redirect, never a rendered response
 *
 * A raise writes a row that outlives the request, so answering it with a page is
 * answering it with something somebody can repeat by pressing reload —
 * `NoticeController`'s rule and `ModulePurchaseController`'s before it. The
 * flash afterwards says what has actually happened, which includes the part
 * nobody enjoys writing: **an operator has not got this yet.** §8.17 is explicit
 * that the delay is stated rather than hidden, on both screens, and this is the
 * customer's half of that.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SupportController extends AbstractController
{
    /**
     * One token for the page.
     *
     * There is one form on it and one person acting on one loaded page, which is
     * what CSRF is about — the notices widget's argument, one deployment over.
     */
    private const string CSRF = 'support';

    public function __construct(
        private readonly SupportTickets $tickets,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/support', name: 'support', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('support/index.html.twig', [
            'tickets' => $this->tickets->all(),

            // The limits, so the form's `maxlength` and the refusal behind it
            // cannot drift apart. The browser stops most of it and the writer
            // refuses the rest, which is the ordinary two-sided arrangement —
            // `maxlength` is a courtesy and {@see SupportTickets::raise()} is the
            // check.
            'max_subject' => SupportTicket::MAX_SUBJECT,
            'max_body' => SupportTicket::MAX_BODY,
        ]);
    }

    #[Route('/support', name: 'support_raise', methods: ['POST'])]
    public function raise(Request $request, #[CurrentUser] User $author): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            // Unlike a dismissal, this one complains: somebody has typed a
            // paragraph and it has not been kept, and sending them back to an
            // empty form without a word would look like the product had eaten it.
            $this->addFlash('error', $this->translator->trans('support.stale'));

            return $this->redirectToRoute('support');
        }

        try {
            $this->tickets->raise(
                (string) $request->request->get('subject'),
                (string) $request->request->get('body'),
                $author,
            );
        } catch (\InvalidArgumentException $e) {
            // Shown verbatim, which is a departure from the usual treatment of a
            // customer-facing message and is deliberate: these say what is wrong
            // with what somebody just typed — empty, or too long by a stated
            // amount — and a catalogue key would either lose the number or need a
            // key per limit.
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('support');
        }

        // Deliberately not a thank-you. What is true is that it has been written
        // down, that it has not reached anybody yet, and that it will — which is
        // §8.15's rule about a purchase flash refusing to congratulate anybody,
        // applied to the one screen where over-promising would be worst.
        $this->addFlash('success', $this->translator->trans('support.raised'));

        return $this->redirectToRoute('support');
    }
}

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

use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\SupportStatus;
use App\Registry\Support\SupportDesk;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\View\SupportRequestListing;

/**
 * Where an operator reads what customers have asked, and answers (XIV-123,
 * docs/architecture/identity-and-access.md §8.17).
 *
 * The fifth page of the console and the second that is not merely reading. It
 * completes [XIV-120]'s pair: that one is where an operator says something to
 * customers, and this is where customers say something back — *"an operator who
 * can broadcast but cannot be replied to"* being an odd thing to have built.
 *
 * ## Every customer's tickets, on one page, from one database
 *
 * **This page opens no tenant connection**, which is [XIV-58]'s boundary and is
 * kept here for free: every row on it is a control-plane row that
 * `tenant:support:collect` put there. A page that went and asked each customer
 * would open one connection per company to draw a list — §8.11's argument, and
 * the reason the collector exists rather than a fan-out.
 *
 * ## What it says about freshness, because it has to
 *
 * Every row is a *collection*, not a ticket, and it carries the moment it was
 * taken. §8.11 settled the rule for the usage figures: a stale figure presented
 * as current is worse than no figure. Here the consequence is sharper — the list
 * being empty means *nothing was collected*, not *nobody has asked*, and an
 * installation whose cron entry was never written would show an operator a clean
 * queue for ever. So the page names the command and says when it last brought
 * anything back.
 *
 * ## Two controls, and both of them write here rather than there
 *
 * Moving the status and writing a reply are `UPDATE`s on a control-plane row
 * that the customer's instance then *reads*. There is no second collector
 * pointing back into the customer's database, and no push: §4.4 gives a
 * customer-facing instance `SELECT` on the registry, so the answer is on the
 * customer's screen the moment it is written here. {@see SupportDesk} is the
 * writer, in `src/` for the reason `NoticeBoard` is.
 *
 * ## No `#[IsGranted]`, and nothing to grant
 *
 * `access_control` requires `ROLE_OPERATOR` for everything under
 * {@see ControlPlaneHost::PATH_PREFIX}, and `ControlPlaneRequestListener` makes
 * these paths not exist on a customer's hostname. Inventing a "may answer
 * tickets" permission before there is a second kind of operator would be
 * modelling a guess (§8.9) — the sentence {@see NoticeController},
 * {@see ModulePricingController} and {@see PurchaseIntentController} all carry.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SupportRequestController extends AbstractController
{
    /** One token for the page: every control on it is the same person acting on one loaded page. */
    private const string CSRF = 'support_request';

    public function __construct(
        private readonly SupportDesk $desk,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(ControlPlaneHost::PATH_PREFIX . '/support', name: 'control_plane_support', methods: ['GET'])]
    public function __invoke(): Response
    {
        $requests = array_map(
            static fn (SupportRequest $request): SupportRequestListing => SupportRequestListing::of($request),
            $this->desk->outstandingFirst(),
        );

        return $this->render('@XiviControlPlane/support.html.twig', [
            'requests' => $requests,

            // Passed separately rather than filtered in the template, because it
            // is the page's headline: these are the people waiting. Drawn only
            // when it is not zero — a banner permanently reading "0 outstanding"
            // is furniture, which is [XIV-58]'s argument for the same shape three
            // pages over.
            'outstanding' => array_values(array_filter(
                $requests,
                static fn (SupportRequestListing $request): bool => $request->isOutstanding(),
            )),

            // The freshest collection on the page, or null when nothing has ever
            // been collected. The second case is the one worth drawing: an empty
            // queue and a job that was never scheduled look identical, and this
            // is what tells them apart.
            'collected_at' => array_reduce(
                $requests,
                static fn (?\DateTimeImmutable $latest, SupportRequestListing $request): \DateTimeImmutable => $latest === null || $request->collectedAt > $latest
                    ? $request->collectedAt
                    : $latest,
            ),

            // Every state the control offers, from the enum rather than from a
            // list in a template — a fourth case would otherwise be invisible on
            // the one screen that sets them.
            'states' => SupportStatus::cases(),
        ]);
    }

    /**
     * Moves one.
     *
     * **POST and a redirect**, never a rendered response: this writes, and a
     * write that answers with a page is a write somebody repeats by pressing
     * reload.
     */
    #[Route(
        ControlPlaneHost::PATH_PREFIX . '/support/{id}/status',
        name: 'control_plane_support_status',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function move(Request $request, int $id): Response
    {
        $found = $this->guard($request, $id);

        if (!$found instanceof SupportRequest) {
            return $found;
        }

        $status = SupportStatus::tryFrom((string) $request->request->get('status'));

        if ($status === null) {
            // Not a message anybody should read: the form offers the cases of an
            // enum. It exists because a hand-made POST is a thing, and a status
            // silently not changing is the worst available answer to one.
            $this->addFlash('error', $this->translator->trans('control_plane.support_unknown_status'));

            return $this->redirectToRoute('control_plane_support');
        }

        $this->desk->moveTo($found, $status);

        $this->addFlash('success', $this->translator->trans('control_plane.support_moved', [
            '%status%' => $this->translator->trans($status->label()),
        ]));

        return $this->redirectToRoute('control_plane_support');
    }

    /**
     * Answers one.
     *
     * The status is deliberately not moved with it — see {@see SupportDesk::reply()}
     * and {@see SupportStatus}: replying and being finished are different things,
     * and a hidden state change on a screen with a visible state control is how
     * the two stop agreeing.
     */
    #[Route(
        ControlPlaneHost::PATH_PREFIX . '/support/{id}/reply',
        name: 'control_plane_support_reply',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function reply(Request $request, int $id): Response
    {
        $found = $this->guard($request, $id);

        if (!$found instanceof SupportRequest) {
            return $found;
        }

        try {
            $this->desk->reply(
                $found,
                (string) $request->request->get('reply'),
                self::authorLabel($this->getUser()),
            );
        } catch (\InvalidArgumentException $e) {
            // Shown verbatim, which is `NoticeController`'s treatment of the same
            // kind of message: these are sentences telling an operator what to do
            // next, written in the domain rather than in the catalogue, and an
            // operator is one of us.
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('control_plane_support');
        }

        $this->addFlash('success', $this->translator->trans('control_plane.support_replied'));

        return $this->redirectToRoute('control_plane_support');
    }

    /**
     * The two things both writes have to check, in one place.
     *
     * Returns the row, or the redirect to send instead. Written as one method
     * because a second control added later that forgot either check would be a
     * control that writes on a stale token or throws on a row somebody else
     * removed.
     */
    private function guard(Request $request, int $id): SupportRequest|Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('control_plane.support_stale'));

            return $this->redirectToRoute('control_plane_support');
        }

        $found = $this->desk->find($id);

        if (!$found instanceof SupportRequest) {
            // A ticket whose customer was deprovisioned while this page was open:
            // the row cascades away with the tenant, which is what the foreign key
            // is for.
            $this->addFlash('error', $this->translator->trans('control_plane.support_gone'));

            return $this->redirectToRoute('control_plane_support');
        }

        return $found;
    }

    /**
     * Who answered, as they are called now, copied onto the row.
     *
     * A customer is shown this string, and the copy is what makes that possible:
     * §4.4 gives a customer-facing instance no access to the `operator` table, so
     * a foreign key would be unreadable by the only party the value is for.
     * {@see NoticeController} does the same for an
     * announcement's author, and for the same two reasons.
     */
    private static function authorLabel(?object $operator): string
    {
        return $operator instanceof Operator ? $operator->getName() : '—';
    }
}

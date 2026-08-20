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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\SignupMailFailed;
use Xivi\ControlPlane\Signup\StalledSignups;

/**
 * The one act this ticket adds: an operator writes to somebody whose signup has
 * stopped for good (XIV-108, §8.14).
 *
 * ## Why this is a controller of its own and the list is not
 *
 * The list is drawn by {@see TenantListController}, in a section of the page an
 * operator already opens, for §8.10's reason: nobody should have to go looking,
 * and a sixth entry in the nav for a table that is empty most days is a page
 * somebody has to remember to exist. XIV-125 put the refusals on the same page
 * on the same argument.
 *
 * The *write* is here instead, and the split is deliberate. That controller's
 * defining property is that it opens no tenant connection and reaches nothing
 * but the registry, and its constructor is where a reader checks that. Giving it
 * a mailer would put a transport in the dependency graph of the page whose whole
 * documented claim is about what it does not reach. This class holds the mailer,
 * has no GET, and renders nothing.
 *
 * ## POST and a redirect, never a rendered response
 *
 * {@see SupportRequestController}'s rule, for its reason: a write that answers
 * with a page is a write somebody repeats by pressing reload, and repeating this
 * one would mean a second apology to the same person. The redirect goes back to
 * the tenant list, where the row now shows when it was sent and by whom instead
 * of a button.
 *
 * ## No `#[IsGranted]`, and nothing to grant
 *
 * `access_control` requires `ROLE_OPERATOR` for everything under
 * {@see ControlPlaneHost::PATH_PREFIX}, and `ControlPlaneRequestListener` makes
 * these paths not exist on a customer's hostname at all. Inventing a "may write
 * to a stranded signup" permission before there is a second kind of operator
 * would be modelling a guess (§8.9), the sentence {@see NoticeController},
 * {@see SupportRequestController}, {@see ModulePricingController} and
 * {@see PurchaseIntentController} all carry.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class StalledSignupController extends AbstractController
{
    /**
     * The tenant list's token, because that is the page these buttons are on.
     *
     * One token per page rather than one per row, which is
     * {@see NoticeController}'s reading of what CSRF is for: every button here
     * is the same operator acting on one loaded page, and a token per row would
     * be a hundred secrets in a form to defend against nothing extra.
     */
    private const string CSRF = 'stalled_signup';

    public function __construct(
        private readonly StalledSignups $stalled,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        ControlPlaneHost::PATH_PREFIX . '/signups/{id}/apology',
        name: 'control_plane_signup_apology',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('control_plane.stalled_stale'));

            return $this->redirectToRoute('control_plane_home');
        }

        $signup = $this->stalled->find($id);

        if (!$signup instanceof SignupRequest) {
            // Either the row is gone because the next cron run provisioned it, or
            // it is no longer stalled because somebody released the name it was
            // waiting for. Both mean the same thing to the operator holding a
            // page from five minutes ago, and both are good news, so the message
            // says so rather than reading as an error about a missing record.
            $this->addFlash('error', $this->translator->trans('control_plane.stalled_gone'));

            return $this->redirectToRoute('control_plane_home');
        }

        try {
            $sent = $this->stalled->apologise($signup, self::authorLabel($this->getUser()));
        } catch (SignupMailFailed $failure) {
            // **Reported rather than swallowed**, which is §8.7's rule and the
            // one this feature would fail worst by breaking: an operator who
            // believes a waiting customer has been written to will not write to
            // them again, and the customer's silence would then be twice as long
            // and nobody's fault. Nothing was recorded, so the button is still
            // there when the page comes back.
            //
            // The exception's own words are shown. An operator is one of us and
            // the message names the transport that refused, which is the only
            // part of this that tells them what to fix.
            $this->addFlash('error', $this->translator->trans('control_plane.stalled_failed', [
                '%reason%' => $failure->getMessage(),
            ]));

            return $this->redirectToRoute('control_plane_home');
        }

        // False when somebody else got there first: two operators with the same
        // page open, one of them a minute behind. Nothing was sent and nothing
        // was written, and the page they land on names who did send it.
        $this->addFlash(
            $sent ? 'success' : 'error',
            $this->translator->trans($sent ? 'control_plane.stalled_sent' : 'control_plane.stalled_already'),
        );

        return $this->redirectToRoute('control_plane_home');
    }

    /**
     * Who sent it, as they are called now, copied onto the row.
     *
     * {@see SupportRequestController::authorLabel()}'s method and very nearly its
     * reason. There it is a copy because a *customer* is shown the string and
     * §4.4 gives their instance no access to the `operator` table. Here nobody
     * outside the control plane ever reads it, and it is still a copy, because
     * withdrawing an operator's access removes their row and must not turn the
     * record of what they did into a blank.
     */
    private static function authorLabel(?object $operator): string
    {
        return $operator instanceof Operator ? $operator->getName() : '—';
    }
}

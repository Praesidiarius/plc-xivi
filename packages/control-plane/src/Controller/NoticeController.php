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

use App\Registry\Entity\Notice;
use App\Registry\Entity\NoticeAudience;
use App\Registry\Entity\Tenant;
use App\Registry\Notice\NoticeBoard;
use App\Registry\Repository\TenantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\View\NoticeListing;
use Xivi\ControlPlane\View\TenantSummary;

/**
 * Where an operator says something to the people using this installation
 * (XIV-120, docs/architecture/identity-and-access.md §8.16).
 *
 * ## The screen the ticket exists for
 *
 * The previous iteration had `LicenseClientNotification` and this one had
 * nothing, which meant whoever runs an installation could see every customer and
 * tell them nothing. Everything an operator knew that a customer needed — a
 * maintenance window, a module that gained a field, a trial about to end — was an
 * email somebody sent by hand from their own client if they remembered.
 *
 * ## Where the row goes, which is the interesting half and is the *opposite* of
 * [XIV-102]'s
 *
 * A purchase request is written by a customer, and §4.4 gives a customer-facing
 * instance no write privilege in the control-plane database — so that row had to
 * live in the customer's own database and be collected back. A notice is written
 * **here**, on the instance that owns the schema, and only *read* by a customer.
 * Reading the registry is exactly what §4.4's grant already permits, so the row
 * is written once, in one place, and read directly: no collector, no interval,
 * no copy, nothing that can be stale.
 *
 * That is why {@see Notice} is an `App\Registry\Entity` class rather than one of
 * this package's. `App\Deployment\RegistryGrants` derives the readable tables
 * from that namespace and no other, so the namespace *is* the grant — see the
 * entity, and `tests/Functional/Deployment/NoticeGrantsTest.php`, which reads a
 * notice as the restricted role rather than trusting the paragraph.
 *
 * ## What "live" means, and why the screen leads with it
 *
 * The ticket's sharpest sentence is that a notice nobody sees is worse than none,
 * *because the operator believes they have told somebody*. So this page is built
 * around the two facts that belief depends on: **what is being shown right now**,
 * and **who it was addressed to**. Both are stated per row, and the count of live
 * notices is the banner.
 *
 * What it does **not** show is whether anybody has read one. That needs a fact
 * out of every customer's database — [XIV-102]'s collector pointed the other way
 * — and §8.16 names it as the gap rather than implying it is coming.
 *
 * ## The boundary [XIV-58] keeps, kept
 *
 * **This page opens no tenant connection.** Every value on it is a control-plane
 * row, and a control-plane request resolves no tenant at all (§8.9). And the
 * `Tenant` entity does not reach the template, for §8.10's reason: a tenant row
 * carries the customer's encrypted database credential, and the defence against
 * that reaching a page is a type. {@see NoticeListing} and {@see TenantSummary}
 * are those types.
 *
 * ## No `#[IsGranted]`, and nothing to grant
 *
 * `access_control` requires `ROLE_OPERATOR` for everything under
 * {@see ControlPlaneHost::PATH_PREFIX}, and `ControlPlaneRequestListener` makes
 * these paths not exist on a customer's hostname. Inventing a "may write
 * notices" permission before there is a second kind of operator would be
 * modelling a guess (§8.9) — the same sentence {@see ModulePricingController}
 * and {@see PurchaseIntentController} both carry.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NoticeController extends AbstractController
{
    /**
     * One token for the page. The publish form and every withdraw button are the
     * same person acting on the same loaded page, which is what CSRF is about.
     */
    private const string CSRF = 'notice';

    public function __construct(
        private readonly NoticeBoard $board,
        private readonly TenantRepository $tenants,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(ControlPlaneHost::PATH_PREFIX . '/notices', name: 'control_plane_notices', methods: ['GET'])]
    public function __invoke(): Response
    {
        $now = new \DateTimeImmutable();

        $notices = array_map(
            static fn (Notice $notice): NoticeListing => NoticeListing::of($notice, $now),
            $this->board->newestFirst(),
        );

        return $this->render('@XiviControlPlane/notices.html.twig', [
            'notices' => $notices,

            // Passed separately rather than filtered in the template, because it
            // is the page's headline and the ticket's own requirement: an
            // operator can see what is live. Drawn only when it is not zero — a
            // banner permanently reading "0 live" is furniture, which is
            // [XIV-58]'s argument for the same shape two pages over.
            'live' => array_values(array_filter(
                $notices,
                static fn (NoticeListing $notice): bool => $notice->live,
            )),

            // Who can be addressed. Summaries rather than tenants, for §8.10's
            // reason; the form posts slugs and {@see NoticeBoard} resolves them,
            // so a customer deprovisioned while this page was open refuses the
            // whole publish rather than silently reaching one fewer company.
            'tenants' => array_map(
                static fn (Tenant $tenant): TenantSummary => TenantSummary::of($tenant),
                $this->tenants->findAllOrdered(),
            ),
        ]);
    }

    /**
     * Publishes one.
     *
     * **POST and a redirect**, never a rendered response: this writes, and a
     * write that answers with a page is a write somebody repeats by pressing
     * reload.
     */
    #[Route(ControlPlaneHost::PATH_PREFIX . '/notices', name: 'control_plane_notice_publish', methods: ['POST'])]
    public function publish(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('control_plane.notice_stale'));

            return $this->redirectToRoute('control_plane_notices');
        }

        $audience = NoticeAudience::tryFrom((string) $request->request->get('audience'));

        if ($audience === null) {
            // Not a message anybody should read: the form offers two radios. It
            // exists because a hand-made POST is a thing, and "it published to
            // somebody else's audience" is the worst available answer to one.
            $this->addFlash('error', $this->translator->trans('control_plane.notice_unknown_audience'));

            return $this->redirectToRoute('control_plane_notices');
        }

        // Null is every customer; a list names them. The two are alternatives
        // rather than a list that happens to be empty — an empty list is refused
        // downstream, because "named nobody" is a notice with no readers and the
        // operator would believe they had told somebody.
        $slugs = $request->request->getBoolean('every_tenant')
            ? null
            : array_values(array_map(strval(...), (array) $request->request->all('tenants')));

        try {
            $notice = $this->board->publish(
                (string) $request->request->get('title'),
                (string) $request->request->get('body'),
                $audience,
                $slugs,
                self::authorLabel($this->getUser()),
                self::expiryFrom($request),
            );
        } catch (\InvalidArgumentException $e) {
            // Shown verbatim. These are sentences telling an operator what to do
            // next — "there is no customer x", "that would expire before anybody
            // could read it" — written in the domain rather than in the
            // catalogue, which is the same treatment `module:state`'s refusals
            // get and for the same reason: an operator is one of us.
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('control_plane_notices');
        }

        $this->addFlash('success', $this->translator->trans(
            $notice->isForEveryTenant() ? 'control_plane.notice_published_all' : 'control_plane.notice_published',
            ['%count%' => \count($notice->getRecipients())],
        ));

        return $this->redirectToRoute('control_plane_notices');
    }

    /**
     * Takes one back.
     *
     * Not a delete: an operator who announced something and then withdrew it has
     * done two things that both happened, and *"did we not tell them?"* is a
     * question somebody asks afterwards. The row stays on this screen, expired.
     */
    #[Route(
        ControlPlaneHost::PATH_PREFIX . '/notices/{id}/withdraw',
        name: 'control_plane_notice_withdraw',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function withdraw(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('control_plane.notice_stale'));

            return $this->redirectToRoute('control_plane_notices');
        }

        $notice = $this->board->find($id);

        if (!$notice instanceof Notice) {
            $this->addFlash('error', $this->translator->trans('control_plane.notice_gone'));

            return $this->redirectToRoute('control_plane_notices');
        }

        $this->board->withdraw($notice);

        $this->addFlash('success', $this->translator->trans('control_plane.notice_withdrawn'));

        return $this->redirectToRoute('control_plane_notices');
    }

    /**
     * When the notice should stop showing, out of the form's optional field.
     *
     * **Read as UTC**, which the form says out loud beside the input. A
     * `datetime-local` carries no zone, this process runs with `date.timezone =
     * UTC` (§8.4.4), and guessing the operator's zone from a header would make
     * the stored moment depend on where somebody was sitting. A customer sees it
     * in their own zone regardless, because that conversion is the one thing this
     * stack does consistently.
     *
     * An unparseable value is refused rather than dropped: a form that silently
     * ignored a date somebody typed would publish something that never expires.
     */
    private static function expiryFrom(Request $request): ?\DateTimeImmutable
    {
        $value = trim((string) $request->request->get('expires_at'));

        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf(
                'That is not a date this can read: "%s". Leave it empty for a notice with no end.',
                $value,
            ));
        }
    }

    /**
     * Who wrote it, as they are called now, copied onto the notice.
     *
     * A customer is shown this string, and the copy is what makes that possible
     * at all: §4.4 gives a customer-facing instance no access to the `operator`
     * table, so a foreign key would be unreadable by the only party the value is
     * for. It is also right for the ordinary reason — an operator later revoked
     * or renamed must not rewrite the authorship of something already published.
     */
    private static function authorLabel(?object $operator): string
    {
        return $operator instanceof Operator ? $operator->getName() : '—';
    }
}

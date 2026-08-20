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

namespace App\Tenant\Notice;

use App\Registry\Entity\Notice;
use App\Registry\Entity\NoticeReach;
use App\Registry\Notice\LiveNotices;
use App\Tenancy\TenantContext;
use App\Tenant\Entity\NoticeDismissal;
use App\Tenant\Entity\User;
use App\Tenant\Repository\NoticeDismissalRepository;
use App\Tenant\Security\UserManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What one person in one installation has been told and has not put away yet
 * (XIV-120, docs/architecture/identity-and-access.md §8.16).
 *
 * **The class where the two databases meet**, which is the whole of this
 * feature's shape in one place: the notices come from the control plane, which a
 * customer's instance may read (§4.4), and the dismissals come from — and go to
 * — the customer's own database, which is the only one it may write. Neither
 * half knows about the other; this is where they are put together, and it is
 * therefore the only place a mistake could show a person a notice they have
 * dismissed or hide one they have not.
 *
 * ## Two queries, in an order that matters
 *
 * The registry is asked first, and the customer's database is not asked at all
 * when the answer is empty. That is the ordinary case for almost every
 * installation almost all of the time — most days nobody is announcing anything
 * — so the dashboard pays one indexed read for a feature that is usually silent,
 * rather than two.
 *
 * It also puts the cheap filter first: what is live and addressed here is a much
 * smaller set than what this person has ever dismissed, which is why
 * {@see NoticeDismissalRepository::dismissedBy()} takes the ids rather than
 * answering about a person in general.
 *
 * ## Dismissing something you cannot see does nothing
 *
 * {@see dismiss()} only writes for a notice that is currently live for this
 * reader. A POST naming anything else is not an error — a page left open while a
 * notice was withdrawn produces exactly that, and a customer should not meet a
 * failure for pressing a button on a card that was true when it was drawn — but
 * it writes nothing, so the table cannot fill with rows about notices this
 * installation was never addressed by.
 *
 * ## Two channels, and only one of them ever reaches the second database
 * (XIV-166)
 *
 * {@see onTheDashboard()} is what the widget draws and is the class as it was:
 * registry, then dismissals, then the difference. {@see onEveryPage()} is what
 * the shell draws, and it stops after the first query, because an every-page
 * notice is not dismissible ({@see NoticeReach::isDismissible()} carries that
 * argument at length).
 *
 * That is not a shortcut, it is the shape of the decision showing through: there
 * is no per-person state for the loud channel in either database, so there is
 * nothing to ask the customer's database about. The consequence is the one worth
 * remembering when reading a query log: **a request for an ordinary page costs
 * exactly one control-plane `SELECT` and never touches the tenant connection on
 * this feature's account**, whether or not the installation has notices, and
 * whatever their reach.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NoticeInbox
{
    public function __construct(
        private LiveNotices $notices,
        private NoticeDismissalRepository $dismissals,
        private TenantContext $context,
        /**
         * **The customer's own database, named rather than autowired.**.
         *
         * The default entity manager is the control plane's, so a bare
         * `EntityManagerInterface` here would try to persist a dismissal into the
         * *registry* — where §4.4's grant would refuse it on a customer-facing
         * instance, which is the good outcome, and where it would quietly
         * succeed on the internal one, which is not. Every writer in `src/Tenant`
         * says which manager it means, for exactly this reason.
         */
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Everything this person should be seeing on their dashboard, newest first.
     *
     * The widget's list, and the class's original method under a name that says
     * which of the two surfaces it is about (XIV-166).
     *
     * @return list<Notice>
     */
    public function onTheDashboard(User $reader): array
    {
        $live = $this->live($reader, NoticeReach::Dashboard);

        if ($live === []) {
            return [];
        }

        $dismissed = $this->dismissals->dismissedBy(
            (int) $reader->getId(),
            array_map(static fn (Notice $notice): int => (int) $notice->getId(), $live),
        );

        return array_values(array_filter(
            $live,
            static fn (Notice $notice): bool => !\in_array((int) $notice->getId(), $dismissed, true),
        ));
    }

    /**
     * Everything this person should be meeting wherever they are, newest first.
     *
     * The shell's band (XIV-166), and **the only method in this class that runs
     * on an ordinary request**: it is called once per page render by
     * {@see \App\Twig\AppChrome::getPageNotices()}, from `_topbar.html.twig`.
     *
     * No dismissal query and no filtering afterwards, which is
     * {@see NoticeReach::isDismissible()}'s decision arriving here as an absence
     * rather than as a condition. Somebody reading this method looking for the
     * missing half should read that one: an every-page notice ends when the
     * operator ends it, and the operator had to say when that is before they
     * could publish it.
     *
     * @return list<Notice>
     */
    public function onEveryPage(User $reader): array
    {
        return $this->live($reader, NoticeReach::EveryPage);
    }

    /**
     * What the registry is currently saying to this person on one channel.
     *
     * @return list<Notice>
     */
    private function live(User $reader, NoticeReach $reach): array
    {
        if (!$this->context->hasTenant()) {
            // No tenant is the login page and every console command. Nobody is
            // being served, so nobody is being told anything.
            return [];
        }

        return $this->notices->forTenant(
            $this->context->getTenant(),
            UserManager::isAdmin($reader),
            $reach,
        );
    }

    /**
     * Puts one away for this person, and for nobody else.
     *
     * Returns whether anything was written, which the controller uses to decide
     * what to say rather than to decide whether to complain: both answers are
     * ordinary.
     *
     * The unique index is what makes a double press one dismissal, and the
     * violation is caught rather than prevented by a prior read — two requests
     * arriving at once both pass a check and only one passes the index
     * ([XIV-109]).
     */
    public function dismiss(int $noticeId, User $reader): bool
    {
        // The dashboard's list and not both channels', which is where XIV-166's
        // decision is actually enforced rather than merely drawn. A hand-made
        // POST naming a live every-page notice finds nothing here and writes
        // nothing, exactly as one naming a notice addressed to another customer
        // does: authority over what may be dismissed is answered by the same
        // query that decides what to draw, so there is no second rule free to
        // disagree with the first.
        $live = $this->onTheDashboard($reader);

        foreach ($live as $notice) {
            if ((int) $notice->getId() !== $noticeId) {
                continue;
            }

            $dismissal = new NoticeDismissal($noticeId, (int) $reader->getId());

            try {
                $this->entityManager->persist($dismissal);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // They pressed it twice. That is one dismissal and it has
                // happened; there is nothing to tell anybody about.
                return false;
            }

            return true;
        }

        return false;
    }
}

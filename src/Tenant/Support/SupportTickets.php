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

namespace App\Tenant\Support;

use App\Registry\Support\CollectedTickets;
use App\Tenancy\TenantContext;
use App\Tenant\Entity\SupportTicket;
use App\Tenant\Entity\User;
use App\Tenant\Repository\SupportTicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Raising a question with whoever runs this installation, and reading what they
 * said back (XIV-123, docs/architecture/identity-and-access.md §8.17).
 *
 * **The class where the two databases meet**, which is this feature's shape in
 * one file — {@see \App\Tenant\Notice\NoticeInbox} is the same sentence for
 * [XIV-120] and the resemblance is the point rather than a coincidence:
 *
 * * the ticket is written **here**, into the customer's own database, because
 *   §4.4 gives their instance no write privilege anywhere in the control plane;
 * * the status and the reply are read **there**, out of the registry, because
 *   `SELECT` on it is what that same grant has always allowed.
 *
 * Neither half knows about the other. This is where they are put together, and
 * therefore the only place a mistake could show somebody another company's
 * answer or lose their own.
 *
 * ## Two queries, in an order that matters
 *
 * The customer's own database is asked first, and the control plane is not asked
 * at all when the answer is empty — which is the ordinary case for almost every
 * installation almost all of the time, because most companies never raise a
 * ticket. So the page pays one read for a feature that is usually silent, and a
 * second only for a customer who has actually asked something.
 *
 * ## Who may raise one: everybody signed in, and no permission exists
 *
 * Decided in §8.17 rather than left to a caller. The short version: raising a
 * ticket commits nothing — no money, no install, no change to the installation —
 * and the person who met the problem is the person who can describe it. Routing
 * it through an administrator means the description travels through somebody who
 * did not see the thing happen, or does not travel at all.
 *
 * A per-installation setting was refused for §8.16's reason pointed the other
 * way: a switch whose only possible effect is to stop somebody with a problem
 * reaching the people who can fix it is not a switch worth building. Every
 * caller here is the signed-in user of a resolved tenant, and the firewall is
 * what enforces that.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SupportTickets
{
    public function __construct(
        private SupportTicketRepository $tickets,
        private CollectedTickets $collected,
        private TenantContext $context,
        /**
         * **The customer's own database, named rather than autowired.**.
         *
         * The default entity manager is the control plane's, so a bare
         * `EntityManagerInterface` here would try to persist a ticket into the
         * *registry* — where §4.4's grant would refuse it on a customer-facing
         * instance, which is the good outcome, and where it would quietly succeed
         * on the internal one, which is not. Every writer in `src/Tenant` names
         * its manager for exactly this reason.
         */
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Everything this company has asked, newest first, with the operator's side
     * of each attached.
     *
     * **The company's, not the reader's.** A ticket is about the installation
     * rather than about a person, and a colleague who asked the same question on
     * Tuesday should find the answer rather than ask it again — which is the
     * whole reason this is a screen instead of an email. The name of whoever
     * raised each one is on the row, so nothing is anonymous; it simply is not
     * private between colleagues, and §8.17 says so where somebody deciding to
     * write something here can read it.
     *
     * @return list<RaisedTicket>
     */
    public function all(): array
    {
        $mine = $this->tickets->newestFirst();

        if ($mine === []) {
            // Nobody has asked anything, so there is nothing over there to ask
            // about. Most installations, most weeks.
            return [];
        }

        $collected = $this->collected->forTenant($this->context->getTenant());

        return array_map(
            static fn (SupportTicket $ticket): RaisedTicket => RaisedTicket::of(
                $ticket,
                $collected[$ticket->getReference()] ?? null,
            ),
            $mine,
        );
    }

    /**
     * Writes one down, and does nothing else whatsoever.
     *
     * The "nothing else" is deliberate and is [XIV-64]'s separation two layers
     * down: **asking and the thing happening are not the same event**. No mail is
     * sent from this installation (§8.7 is a whole section on how much has to be
     * true before that works, and none of it should stand between somebody with a
     * problem and the people who can fix it), nothing is pushed anywhere, and no
     * operator is woken up. The row waits for `tenant:support:collect`.
     *
     * @throws \InvalidArgumentException when there is nothing to send, or so much
     *                                   of it that the column would refuse
     */
    public function raise(string $subject, string $body, ?User $author): SupportTicket
    {
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || $body === '') {
            throw new \InvalidArgumentException('A ticket needs a subject and something to say.');
        }

        if (mb_strlen($subject) > SupportTicket::MAX_SUBJECT) {
            throw new \InvalidArgumentException(sprintf(
                'A subject is at most %d characters; the rest belongs in the description.',
                SupportTicket::MAX_SUBJECT,
            ));
        }

        if (mb_strlen($body) > SupportTicket::MAX_BODY) {
            throw new \InvalidArgumentException(sprintf(
                'A description is at most %d characters.',
                SupportTicket::MAX_BODY,
            ));
        }

        $ticket = new SupportTicket($subject, $body, $author?->getId(), self::labelOf($author));

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $ticket;
    }

    /**
     * What to write down about who asked.
     *
     * {@see \App\Store\PurchaseRequests::labelOf()}'s decision, copied because it
     * is the same one: the name on a request is the name it was made under, and
     * null — a console caller, a future automation — gets a word rather than an
     * empty cell, because a blank in this column reads as a bug rather than as an
     * answer.
     */
    private static function labelOf(?User $author): string
    {
        return $author?->getName() ?? '—';
    }
}

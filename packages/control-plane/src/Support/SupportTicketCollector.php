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

namespace Xivi\ControlPlane\Support;

use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\SupportTicket;
use App\Tenant\Repository\SupportTicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Goes and looks at one customer's database, and brings back what they have
 * asked (XIV-123, docs/architecture/identity-and-access.md §8.17).
 *
 * ## Why a collector at all
 *
 * Because a customer's request cannot reach the control-plane database, and that
 * is [XIV-96]'s guarantee rather than an accident of wiring. §4.4 grants the
 * customer-facing instance's role `SELECT` on the registry tables and **nothing
 * else** — no `INSERT` anywhere, on any table, present or future — so a ticket
 * written by a customer's own request has exactly one database available to it:
 * theirs. This is [XIV-102]'s collector pointed at a different table, and it is
 * deliberately the same shape rather than a new one.
 *
 * The tempting alternative is the same one, and it is refused for the same
 * sentence: **have the customer's instance POST to a control-plane endpoint**.
 * It removes the interval, it is the pattern [XIV-65]'s landing page uses
 * against [XIV-64]'s intake, and here it would hand the public image a
 * credential that writes the control plane over HTTP — re-obtaining through a
 * network call precisely the privilege the database refuses it. §4.4's whole
 * argument is that the sharp boundary is the grant rather than the topology.
 *
 * **What that costs, said plainly:** an operator learns about a ticket within
 * one collection interval rather than the instant it is raised. §8.17 decides
 * that this is acceptable *in this direction only*, and the reason is that the
 * other direction does not pay it: an operator's reply and status live on the
 * row this class writes, in the control plane, and a customer reads them
 * directly out of the registry, so the answer is immediate. The half of the
 * conversation that waits is the half where nobody is watching a screen. Both
 * screens say which is which rather than leaving somebody to work it out.
 *
 * ## Two cases, and the third one is missing on purpose
 *
 * A ticket seen before is refreshed in place; one not seen before is inserted.
 * **A row here whose ticket has gone from the customer's database is left
 * standing**, which is exactly where this differs from
 * {@see \Xivi\ControlPlane\Purchase\PurchaseIntentCollector} — and the asymmetry
 * is a decision rather than an omission.
 *
 * A purchase request that has gone is a request that no longer exists anywhere,
 * so deleting the copy keeps a queue honest. A support ticket that has gone is
 * something else: the operator's half of it — the status, the reply, who wrote
 * it and when — exists **only here**, and deleting the row would destroy the
 * answer rather than tidy up after it. The case that produces it is a customer
 * database rebuilt from scratch, and *"we answered them in March and then their
 * database was rebuilt"* is a question somebody asks. So the row stays, and
 * nothing in this system deletes a support ticket.
 *
 * ## The operator's columns are never touched
 *
 * {@see SupportRequest::record()} writes the subject, the body and the moment,
 * and nothing else. A collection that rewrote the whole row would discard an
 * answer whenever it overlapped with somebody typing one, on a job that runs
 * every few minutes — a race whose one visible symptom is a customer being shown
 * their own question back. `SupportRequestTest` runs a collection over a replied
 * ticket for that reason.
 *
 * ## Who asked does not cross
 *
 * The tenant-side row carries the person's id and the name they had at the time,
 * and neither leaves that database — [XIV-102]'s line, held here where crossing
 * it would be more tempting. An operator answers the *company*, on the screen
 * the company reads, and never learns which of a customer's staff typed the
 * question.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SupportTicketCollector
{
    public function __construct(
        private TenantSwitcher $switcher,
        private SupportTicketRepository $tickets,
        private EntityManagerInterface $controlPlane,
    ) {
    }

    /**
     * Collect one customer's tickets, whatever happens.
     *
     * Returns rather than throws for anything the customer's database does,
     * which is `UsageCollector`'s choice for its reason: one unreachable database
     * must not cost the other forty-nine their place in the queue. A failure
     * writes nothing at all and leaves the previous collection standing — the
     * purchase collector's asymmetry, and here it matters more, because blanking
     * a customer's outstanding questions over a network hiccup would lose
     * somebody's problem.
     */
    public function collect(Tenant $tenant): CollectionReport
    {
        try {
            /** @var array<string, array{subject: string, body: string, raisedAt: \DateTimeImmutable}> $found */
            $found = $this->switcher->runFor($tenant, function (): array {
                $tickets = [];

                foreach ($this->tickets->newestFirst() as $ticket) {
                    \assert($ticket instanceof SupportTicket);

                    // Scalars out of the switch rather than entities. The
                    // customer's connection is closed the moment `runFor`
                    // returns, so an entity carried across would be one whose
                    // manager has gone — and the two columns deliberately left
                    // behind here (who raised it) are left behind by not being
                    // in this array at all, rather than by somebody remembering
                    // not to read them.
                    $tickets[$ticket->getReference()] = [
                        'subject' => $ticket->getSubject(),
                        'body' => $ticket->getBody(),
                        'raisedAt' => $ticket->getRaisedAt(),
                    ];
                }

                return $tickets;
            });
        } catch (\Throwable $e) {
            // `\Throwable` rather than a list of driver exceptions, for
            // `UsageCollector`'s reason: an unreachable database and one with no
            // schema are two exceptions and one fact for this run.
            return CollectionReport::couldNotRead($e);
        }

        $existing = $this->existingByReference($tenant);
        $new = 0;

        foreach ($found as $reference => $ticket) {
            $request = $existing[$reference] ?? null;

            if ($request === null) {
                $request = new SupportRequest($tenant, $reference);
                $this->controlPlane->persist($request);
                ++$new;
            }

            $request->record($ticket['subject'], $ticket['body'], $ticket['raisedAt']);
        }

        $this->controlPlane->flush();

        return CollectionReport::collected(\count($found), $new);
    }

    /**
     * What is already here for this customer, keyed by their reference.
     *
     * **Scoped to the tenant, which is the half that matters.** A reference is a
     * value produced inside a customer's database; looking one up without the
     * tenant would let a customer name a row belonging to another company by
     * producing the same string, and the unique index is on the pair for the same
     * reason.
     *
     * @return array<string, SupportRequest>
     */
    private function existingByReference(Tenant $tenant): array
    {
        $byReference = [];

        /** @var list<SupportRequest> $rows */
        $rows = $this->controlPlane->getRepository(SupportRequest::class)->findBy(['tenant' => $tenant]);

        foreach ($rows as $row) {
            $byReference[$row->getReference()] = $row;
        }

        return $byReference;
    }
}

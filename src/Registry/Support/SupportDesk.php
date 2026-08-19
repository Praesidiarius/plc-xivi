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

namespace App\Registry\Support;

use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\SupportStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Answering a customer, and saying where their question has got to (XIV-123,
 * docs/architecture.md §8.17).
 *
 * ## A writer in `src/` whose only callers are in the administration surface
 *
 * The fourth of them, and §4.4 keeps the list: `ModuleCatalog::moveTo()`,
 * `ModuleCatalog::priceAt()`, `Registry\Notice\NoticeBoard` and now this. The
 * arrangement is forced by the same thing every time — the *entity* has to be
 * `App\Registry\Entity` for the customer-facing role to be granted `SELECT` on
 * its table ({@see SupportRequest} has the argument), and a class that writes
 * those rows cannot live where the application may not depend on it.
 *
 * **The guarantee is the grant and not this file's reachability.** §4.4 is
 * emphatic: a method that cannot be called today is one refactor from being
 * called, and what actually stops a customer's instance closing its own tickets
 * or writing itself a reply is a database role with no `UPDATE`.
 * `tests/Functional/Deployment/SupportGrantsTest.php` proves that against a real
 * role rather than against this paragraph.
 *
 * ## The refusals, and why they are here rather than on a form
 *
 * A form is one caller. These are the conditions under which an answer would be
 * a lie or a no-op, and the class that writes the row is the last place that can
 * tell:
 *
 * * **An empty reply.** The customer's screen draws a reply block the moment
 *   there is one, so a blank reply is a card on somebody's page saying that they
 *   have been answered, with nothing in it.
 * * **A reply longer than the column.** Refused as a sentence rather than as a
 *   driver exception, which is {@see \App\Registry\Notice\NoticeBoard}'s
 *   treatment of a long title.
 *
 * The messages are sentences rather than translation keys, for the reason
 * `NoticeBoard` gives: they are read by an operator, who is one of us, and they
 * are diagnostics rather than page copy.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SupportDesk
{
    /**
     * How long an answer may be.
     *
     * The column is `TEXT` and this is a limit on a person, the mirror of
     * {@see \App\Tenant\Entity\SupportTicket::MAX_BODY}: a reply that is longer
     * than the question is usually documentation, and documentation belongs on
     * the documentation site (§8.17).
     */
    private const int MAX_REPLY = 8000;

    public function __construct(
        private EntityManagerInterface $control,
    ) {
    }

    /**
     * Every ticket from every customer, the ones somebody still owes an answer
     * for first.
     *
     * **From every customer, and the ordering is the whole of the page.** The
     * tenant is fetch-joined because the screen prints which company each ticket
     * came from and doing it lazily would be one query per row on a page whose
     * entire content is this list — [XIV-58]'s rule for the tenant list next
     * door.
     *
     * Closed tickets stay, dimmed and at the bottom. *"What did they ask us in
     * March"* is a question somebody asks, and a list that answered it by having
     * no row would answer it wrongly — the purchase screen's argument for keeping
     * fulfilled requests, reused.
     *
     * @return list<SupportRequest>
     */
    public function outstandingFirst(): array
    {
        // The `CASE` takes its state as a parameter rather than as a literal:
        // the column is mapped with `enumType`, so Doctrine converts a parameter
        // and would compare a hand-written literal against whatever the string
        // happened to be. One place knows that `Closed` is spelled `closed`, and
        // it is the enum.
        $query = $this->control->createQuery(
            <<<'DQL'
                SELECT s, t FROM App\Registry\Entity\SupportRequest s
                INNER JOIN s.tenant t
                ORDER BY CASE WHEN s.status = :closed THEN 1 ELSE 0 END ASC, s.raisedAt ASC
                DQL,
        );

        $query->setParameter('closed', SupportStatus::Closed->value);

        /** @var list<SupportRequest> $rows */
        $rows = $query->getResult();

        return $rows;
    }

    public function find(int $id): ?SupportRequest
    {
        return $this->control->find(SupportRequest::class, $id);
    }

    /**
     * Moves one, and that is the whole of the operator's control over state.
     *
     * Flushes rather than leaving it to a caller, because the caller is a
     * controller and a write that depends on somebody else remembering to flush
     * is a write that silently does not happen.
     */
    public function moveTo(SupportRequest $request, SupportStatus $status): void
    {
        $request->moveTo($status);

        $this->control->flush();
    }

    /**
     * Answers one.
     *
     * The status is **not** moved as a side effect, which is a decision rather
     * than a gap: replying and being finished are different things, an operator
     * who answers and expects to hear more leaves the ticket in progress, and a
     * hidden state change on a screen with a visible state control is how the
     * two stop agreeing. {@see SupportStatus} has the longer form — there is no
     * `Answered` state for the same reason.
     *
     * @throws \InvalidArgumentException when the reply would be empty or longer
     *                                   than the screen it lands on can carry
     */
    public function reply(SupportRequest $request, string $reply, string $authorLabel): void
    {
        $reply = trim($reply);

        if ($reply === '') {
            throw new \InvalidArgumentException(
                'An empty reply would put an answer on the customer\'s screen with nothing in it.',
            );
        }

        if (mb_strlen($reply) > self::MAX_REPLY) {
            throw new \InvalidArgumentException(sprintf(
                'A reply is at most %d characters; this one is %d.',
                self::MAX_REPLY,
                mb_strlen($reply),
            ));
        }

        $request->replyWith($reply, $authorLabel);

        $this->control->flush();
    }
}

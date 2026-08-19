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

namespace App\Tenant\Entity;

use App\Tenant\Repository\NoticeDismissalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person has read one of the operator's notices and put it away (XIV-120,
 * docs/architecture/identity-and-access.md §8.16).
 *
 * ## Why this row is here and the notice itself is not
 *
 * §4.4, again, and from the same side as [XIV-102]. The customer-facing
 * instance's database role holds `SELECT` on the registry tables and **no write
 * privilege anywhere** in the control-plane database — so a notice can be read
 * across that boundary, which is what makes this feature cheap, and a dismissal
 * cannot be written across it, which is what puts this table in the customer's
 * own database. The feature reads on one side of the boundary and writes on the
 * other, which is exactly the arrangement the grant was built to force.
 *
 * It is also where the row belongs on the merits: whether *this person* has seen
 * something is a fact about this installation's people, and §8.11's line — an
 * operator learns how much a customer uses, never who their people are — would
 * be crossed by a table of names and reading habits in a database the customer
 * cannot see.
 *
 * ## Per person, not per installation
 *
 * Dismissing is *"I have read this"*. A tenant-wide dismissal would let whoever
 * opened the dashboard first take a maintenance window off everybody else's
 * screen, and the notice they never saw is the failure this whole ticket is
 * against: *a notice nobody sees is worse than none, because the operator
 * believes they have told somebody.*
 *
 * ## {@see $noticeId} is an integer with nothing to point at
 *
 * The row it names is in another database, so there is no foreign key available
 * and none is wanted. That makes it the same kind of value as a saved dashboard
 * layout's widget key (§8.3.1) and a stale `reference` (§7.6): **data referring
 * to something outside this database, resolved where it is read and simply
 * dropped when it resolves to nothing.** A dismissal of a notice that has since
 * been deleted hides a notice that no longer exists, which is the correct
 * outcome and needs no repair — and there is deliberately no process hunting
 * orphans, because a cross-database garbage collector is a much worse thing to
 * own than a few bytes.
 *
 * {@see $userId} has no foreign key either, which is `follow_up`'s decision:
 * nothing here should make removing a person fail. Unlike `follow_up` there is
 * no label copied beside it, because nobody is ever shown who dismissed what —
 * this row is written and read by the same person and appears on no screen.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: NoticeDismissalRepository::class)]
#[ORM\Table(name: 'notice_dismissal')]
#[ORM\UniqueConstraint(name: 'uniq_notice_dismissal', columns: ['notice_id', 'user_id'])]
#[ORM\Index(name: 'idx_notice_dismissal_user', columns: ['user_id'])]
class NoticeDismissal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'dismissed_at')]
    private \DateTimeImmutable $dismissedAt;

    public function __construct(
        /** The control plane's `notice.id`. See the class docblock: it is a number, not a join. */
        #[ORM\Column(name: 'notice_id')]
        private int $noticeId,
        #[ORM\Column(name: 'user_id')]
        private int $userId,
    ) {
        $this->dismissedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNoticeId(): int
    {
        return $this->noticeId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getDismissedAt(): \DateTimeImmutable
    {
        return $this->dismissedAt;
    }
}

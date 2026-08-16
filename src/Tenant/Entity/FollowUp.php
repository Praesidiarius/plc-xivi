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

use App\Tenant\Repository\FollowUpRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something somebody decided to do about one record, by one date (XIV-80).
 *
 * **One table for every module, which is the opposite of what history does, and
 * the difference is who writes the rows.** §5.2 splits history per module for two
 * reasons: a shared `history(entity_type, entity_id)` table could carry no
 * foreign key, and at 60M rows the planner had nothing to narrow on. The second
 * reason does not survive the move here. History is written automatically, on
 * every save, by everybody, and grows without bound; a follow-up is typed by a
 * person who decided to type it, and a customer who produces a thousand of them a
 * year is a busy customer. So the size argument buys nothing and the cost of one
 * table per module — a `ModuleInstaller` that creates it, the 63-character
 * identifier guard in `assertTableNameFits()` to widen, an installed module that
 * predates the feature to retro-fit — is paid for nothing in return.
 *
 * What does *not* survive is the integrity. `record_id` means a row in a
 * different table depending on what `module` says, so **it carries no foreign
 * key and cannot**. That is precisely the property §5.2 refused to give up for
 * history, given up here deliberately and with the reason written down: this
 * table is hand-written, small, and read through a module definition that is
 * always in hand at the point of asking, so nothing here ever has to work out
 * which table a row means from the row alone.
 *
 * **Two consequences of having no foreign key, and both are somebody's job:**
 *
 *  * Records are soft-deleted (`RecordRepository::delete()` sets `deleted_at`),
 *    so a cascade would have nothing to fire on even if there were one. Follow-ups
 *    on a deleted record become unreachable because their record is — and every
 *    read therefore has to check, which {@see FollowUpRepository} does and says
 *    why.
 *  * A hard purge, when one is ever built, has to sweep this table itself.
 *    Deleting the rows of `contact` will not touch the follow-ups naming them,
 *    because there is no constraint to notice, and nothing in Postgres will
 *    remind whoever writes that purge. This paragraph is the reminder.
 *
 * **Users are denormalised, exactly as in history.** `assignee_id` and
 * `created_by_id` carry no foreign key and sit beside a label captured at write
 * time. Unlike core — which stores an owner id without a key because it genuinely
 * does not know what a user is — these entities live next to {@see User} in the
 * same database and *could* have one. The choice is still no, for two reasons
 * that point the same way: a follow-up should outlive the person it was assigned
 * to (a task does not stop existing because somebody left), and a label captured
 * at write time keeps saying who they were even after a rename, which is the same
 * argument §5.2 makes for `user_label`. What deleting a user does do is clear the
 * *assignment* — see {@see \App\Tenant\EventListener\UserRemovedListener}, which
 * is a listener precisely because no constraint will do it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: FollowUpRepository::class)]
#[ORM\Table(name: 'follow_up')]
// The record page's question — "what is outstanding on this contact" — and the
// only one it asks. Leading with the module rather than the record id because a
// record id on its own means nothing here.
#[ORM\Index(name: 'idx_follow_up_record', columns: ['module', 'record_id'])]
// The widget's question (XIV-81): what is on my list, still open, soonest first.
// Two indexes and no more. Over-indexing is half of what made the old history
// table hurt (§5.2), and this table is the one that will be written to by hand.
#[ORM\Index(name: 'idx_follow_up_assignee', columns: ['assignee_id', 'done_at', 'due_at'])]
#[ORM\HasLifecycleCallbacks]
class FollowUp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The notes written on this one, oldest first.
     *
     * A thread reads forwards: the follow-up is the question and the notes are
     * what happened about it, which is the one place in this application where
     * newest-first would be wrong (history is the other way for the opposite
     * reason — nobody re-reads a timeline from the beginning).
     *
     * Removing a follow-up takes its notes with it, in the object graph here and
     * by a real `ON DELETE CASCADE` in the database. That foreign key exists
     * because unlike `record_id` this one means exactly one table, which is the
     * whole of §5.2's argument arriving at the opposite answer within one class.
     *
     * @var Collection<int, FollowUpNote>
     */
    #[ORM\OneToMany(targetEntity: FollowUpNote::class, mappedBy: 'followUp', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $notes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Last activity on the *thread*, not the last edit of this row's own fields.
     *
     * Writing a note bumps it, which is the point: somebody scanning a list wants
     * "when did anything last happen here", and a follow-up whose timestamp stood
     * still while three people argued underneath it would answer a question
     * nobody asked. {@see touch()} is how the notes reach it.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * When it was finished, or null while it still wants doing.
     *
     * **Done is a timestamp and not a state**, which is what makes reopening
     * possible without a state machine: null means active, a time means archived,
     * and moving between them is one assignment in either direction. A boolean
     * would have lost when, and a lifecycle (§5.8) would have brought transitions
     * and a marking store to describe a thing with two positions.
     */
    #[ORM\Column(name: 'done_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    /**
     * @param string $module   the module *key*, not its table — the same string a
     *                         grant names and a route carries, so a customer
     *                         renaming their module moves nothing here
     * @param int    $recordId the id of a record of that module, checked to exist
     *                         and not be soft-deleted by the write path rather
     *                         than by a constraint there cannot be
     */
    public function __construct(
        #[ORM\Column(length: 63)]
        private string $module,
        #[ORM\Column(name: 'record_id')]
        private int $recordId,
        #[ORM\Column(length: 15, enumType: FollowUpPriority::class)]
        private FollowUpPriority $priority,
        /**
         * When it wants dealing with.
         *
         * `timestamptz`, like `<module>_history.occurred_at` and never a zoneless
         * `timestamp` — a date somebody in Zürich set and somebody in Lisbon reads
         * is one instant described twice, and a column with no zone is a column
         * that has quietly picked the server's.
         */
        #[ORM\Column(name: 'due_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $dueAt,
        /** Who made it. No foreign key, a label beside it — see the class docblock. */
        #[ORM\Column(name: 'created_by_id')]
        private int $createdById,
        #[ORM\Column(name: 'created_by_label', type: Types::TEXT)]
        private string $createdByLabel,
        /**
         * Whose list it is on, or nobody's.
         *
         * Null is a real state rather than a missing one: a follow-up on a record
         * is a note the office keeps, and forcing an assignee on it would make
         * "somebody should call them back" into an accusation.
         */
        #[ORM\Column(name: 'assignee_id', nullable: true)]
        private ?int $assigneeId = null,
        #[ORM\Column(name: 'assignee_label', type: Types::TEXT, nullable: true)]
        private ?string $assigneeLabel = null,
    ) {
        $this->notes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getRecordId(): int
    {
        return $this->recordId;
    }

    public function getPriority(): FollowUpPriority
    {
        return $this->priority;
    }

    public function setPriority(FollowUpPriority $priority): void
    {
        $this->priority = $priority;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(\DateTimeImmutable $dueAt): void
    {
        $this->dueAt = $dueAt;
    }

    public function getCreatedById(): int
    {
        return $this->createdById;
    }

    public function getCreatedByLabel(): string
    {
        return $this->createdByLabel;
    }

    public function getAssigneeId(): ?int
    {
        return $this->assigneeId;
    }

    public function getAssigneeLabel(): ?string
    {
        return $this->assigneeLabel;
    }

    /**
     * Puts it on somebody's list, or takes it off everybody's.
     *
     * The label is passed in rather than read off a User, because the one case
     * this has to survive is the user being gone: unassigning keeps the label
     * (see {@see clearAssignee()}) and reassigning replaces both halves together.
     * Two arguments that must agree is a smaller risk than an entity reference
     * that would have to be nullable, joined, and eventually orphaned.
     */
    public function assignTo(int $assigneeId, string $assigneeLabel): void
    {
        $this->assigneeId = $assigneeId;
        $this->assigneeLabel = $assigneeLabel;
    }

    /** Nobody's, and nobody's name on it either — what the form's empty option means. */
    public function unassign(): void
    {
        $this->assigneeId = null;
        $this->assigneeLabel = null;
    }

    /**
     * The assignee is gone; the follow-up is not.
     *
     * Distinct from {@see unassign()} on purpose, and the difference is the label.
     * A follow-up nobody chose to unassign still says whose it was, so a list of
     * unassigned work reads "Marta Beck (no longer here)" rather than losing the
     * only clue about who was going to do it.
     */
    public function clearAssignee(): void
    {
        $this->assigneeId = null;
    }

    public function getDoneAt(): ?\DateTimeImmutable
    {
        return $this->doneAt;
    }

    public function isDone(): bool
    {
        return $this->doneAt !== null;
    }

    public function markDone(\DateTimeImmutable $at): void
    {
        $this->doneAt = $at;
    }

    public function reopen(): void
    {
        $this->doneAt = null;
    }

    /** @return Collection<int, FollowUpNote> */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function addNote(FollowUpNote $note): void
    {
        $this->notes->add($note);
    }

    public function removeNote(FollowUpNote $note): void
    {
        $this->notes->removeElement($note);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Says that something happened on this thread, without anything on this row
     * having changed.
     *
     * Called when a note is written, edited or removed. It exists because
     * `#[ORM\PreUpdate]` fires on a *changed row*, and a note is a different row:
     * without this, editing a note would leave the follow-up's timestamp saying
     * it had been quiet since the day it was made. Setting the field here also
     * makes the follow-up dirty, which is what gets the UPDATE issued at all.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

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

use App\Tenant\Repository\FollowUpNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing somebody said about a follow-up (XIV-80).
 *
 * **A real foreign key, cascading**, and that is not in tension with the missing
 * one on `follow_up.record_id` — it is the same rule producing the opposite
 * answer. §5.2's whole argument is that a relating id which means a different
 * table depending on a neighbouring column cannot carry a constraint; this one
 * means `follow_up` and nothing else, forever, so it carries one. A note with no
 * follow-up is not a note about anything, and there is no reading of "keep it"
 * that helps anybody.
 *
 * **The author is a denormalised id and a label**, as on the follow-up itself and
 * for the same reasons — see {@see FollowUp}. What is different here is what the
 * id is *used* for: it is not only provenance, it is the authorization. Editing
 * and deleting a note are allowed to its author and to nobody else, without a
 * grant and without an administrator override, so this column is read by
 * {@see \App\Tenant\FollowUp\FollowUpManager} on every write. A deleted user's
 * notes therefore become nobody's to edit, which is the correct end state: the
 * sentence stays, attributed, and stops being editable by anyone at all.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: FollowUpNoteRepository::class)]
#[ORM\Table(name: 'follow_up_note')]
#[ORM\HasLifecycleCallbacks]
class FollowUpNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * When this note was last rewritten.
     *
     * Its own, and separate from the parent's: the follow-up's `updated_at` says
     * when anything last happened on the thread, and this one says when this
     * paragraph last changed. Reading them together is how a page can show
     * "edited" against one note without claiming the whole thread moved.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        /**
         * The thread this belongs to.
         *
         * Attached from this side in the constructor, so a note cannot exist
         * detached for the length of a statement — the same shape
         * {@see \Xivi\Core\Entity\CollectionDefinition} uses with its parent.
         */
        #[ORM\ManyToOne(targetEntity: FollowUp::class, inversedBy: 'notes')]
        #[ORM\JoinColumn(name: 'follow_up_id', nullable: false, onDelete: 'CASCADE')]
        private FollowUp $followUp,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        /** Who wrote it. No foreign key, and it decides who may rewrite it. */
        #[ORM\Column(name: 'author_id')]
        private int $authorId,
        #[ORM\Column(name: 'author_label', type: Types::TEXT)]
        private string $authorLabel,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;

        $followUp->addNote($this);
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

    public function getFollowUp(): FollowUp
    {
        return $this->followUp;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function getAuthorId(): int
    {
        return $this->authorId;
    }

    public function getAuthorLabel(): string
    {
        return $this->authorLabel;
    }

    /**
     * Whether this person is the one who wrote it.
     *
     * Here rather than a comparison at the call site because it is the whole of
     * the note's access rule, and a rule expressed as `getAuthorId() === $id`
     * scattered over three callers is a rule with three chances to be written
     * with the wrong id. A null user is never the author — a console command has
     * written no notes.
     */
    public function isAuthoredBy(?int $userId): bool
    {
        return $userId !== null && $this->authorId === $userId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Whether it has been rewritten since it was written. */
    public function isEdited(): bool
    {
        return $this->updatedAt > $this->createdAt;
    }
}

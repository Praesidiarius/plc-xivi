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

namespace App\Tenant\FollowUp;

use App\Tenant\Entity\FollowUp;
use App\Tenant\Entity\FollowUpNote;
use App\Tenant\Entity\FollowUpPriority;
use App\Tenant\Entity\User;
use App\Tenant\Security\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * Every way a follow-up can be written, and every rule about who may (XIV-80).
 *
 * **The rules live here rather than in the screens**, and that is the whole
 * reason this class exists rather than a controller holding a repository. A
 * permission checked only where the button is drawn is a permission that holds
 * until the first import, the first console command or the first API call — and
 * this feature is exactly the kind that grows one of those. §8.4's three
 * enforcement seams are the route, the voter and the query predicate; a service
 * layer is where the fourth belongs, underneath all three, and XIV-82 will still
 * carry `#[IsGranted]` on the routes so the refusal happens before the action
 * runs.
 *
 * **The actor is a parameter, never read off the token**, which is the same
 * argument one level down. A manager that asked `Security::getUser()` would work
 * on a form post and refuse everything else, so "who is doing this" is passed in
 * — and the assignee check below then costs nothing, because resolving somebody
 * *other* than the current user was already the shape of the code.
 *
 * What it enforces, in one place so the list can be read:
 *
 * * the module must exist for this customer and must take follow-ups;
 * * the record must exist and must not be soft-deleted;
 * * creating, and writing a note, needs `follow_up_create` on the module;
 * * marking done and reopening need `follow_up_complete` — one grant for both
 *   directions;
 * * a grant scoped to "own records" is honoured against the record's owner, the
 *   same way {@see \App\Tenant\Security\RecordPermissionVoter} does for a record;
 * * a note may be edited and deleted by its author, and by nobody else at all;
 * * an assignee must be able to view the record the follow-up sits on.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FollowUpManager
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
        private MetadataRepository $metadata,
        private RecordRepository $records,
        private PermissionResolver $permissions,
    ) {
    }

    /**
     * Opens one, optionally on somebody's list and optionally with the first note
     * already written.
     *
     * The note is folded into this call rather than left to a second one because
     * "call them back — they asked about the second invoice" is one thought, and
     * two calls would mean a form that half-succeeded when the second one was
     * refused.
     *
     * @throws FollowUpRefused
     */
    public function create(
        User $actor,
        string $moduleKey,
        int $recordId,
        FollowUpPriority $priority,
        \DateTimeImmutable $dueAt,
        ?User $assignee = null,
        string $note = '',
    ): FollowUp {
        $module = $this->module($moduleKey);
        $record = $this->record($module, $recordId);

        $this->assertMay($actor, $module, $record, ModuleAction::FollowUpCreate);

        $followUp = new FollowUp(
            module: $module->getKey(),
            recordId: $recordId,
            priority: $priority,
            dueAt: $dueAt,
            createdById: $this->identify($actor),
            createdByLabel: $actor->getName(),
        );

        if ($assignee !== null) {
            $this->assertAssigneeMayView($module, $assignee);
            $followUp->assignTo($this->identify($assignee), $assignee->getName());
        }

        $this->entityManager->persist($followUp);

        if (trim($note) !== '') {
            // Before the flush, so the follow-up and its first note are one row
            // each written in one unit of work rather than a follow-up that
            // exists and a note that did not make it.
            $this->write($followUp, $actor, $note);
        }

        $this->entityManager->flush();

        return $followUp;
    }

    /**
     * Moves it onto somebody's list, or off everybody's.
     *
     * Governed by `follow_up_create` rather than a verb of its own: handing a
     * task to a colleague and opening one for them are the same act with the
     * same consequence, and a third grant would be a cell in the permission
     * matrix nobody could explain the difference of.
     *
     * @throws FollowUpRefused
     */
    public function assign(User $actor, FollowUp $followUp, ?User $assignee): void
    {
        $module = $this->module($followUp->getModule());
        $record = $this->record($module, $followUp->getRecordId());

        $this->assertMay($actor, $module, $record, ModuleAction::FollowUpCreate);
        $this->assertNotDone($followUp);

        if ($assignee === null) {
            $followUp->unassign();
        } else {
            $this->assertAssigneeMayView($module, $assignee);
            $followUp->assignTo($this->identify($assignee), $assignee->getName());
        }

        $followUp->touch();
        $this->entityManager->flush();
    }

    /**
     * Adds something to the thread.
     *
     * `follow_up_create`, because a note is what a follow-up is for — see
     * {@see ModuleAction::FollowUpCreate} for why that is one grant and not two.
     *
     * @throws FollowUpRefused
     */
    public function addNote(User $actor, FollowUp $followUp, string $body): FollowUpNote
    {
        $module = $this->module($followUp->getModule());
        $record = $this->record($module, $followUp->getRecordId());

        $this->assertMay($actor, $module, $record, ModuleAction::FollowUpCreate);
        $this->assertNotDone($followUp);

        $note = $this->write($followUp, $actor, $body);
        $this->entityManager->flush();

        return $note;
    }

    /**
     * Rewrites one, if it is yours.
     *
     * **No grant is consulted and there is no administrator override**, which is
     * the one place this feature departs from §8.4 and does it on purpose. A note
     * is a sentence somebody said; editing it under their name is putting words
     * in their mouth, and there is no configuration of a permission system that
     * should make that possible. The refusal is therefore not "you lack a grant"
     * but "that is not yours", and no amount of granting changes it.
     *
     * The module and the record are still resolved, because a note on a record
     * that has been deleted, or in a module whose follow-ups have been switched
     * off, is not one anybody should be editing either.
     *
     * @throws FollowUpRefused
     */
    public function editNote(User $actor, FollowUpNote $note, string $body): void
    {
        $this->assertOwnNote($actor, $note);
        $this->assertNotDone($note->getFollowUp());

        $body = trim($body);

        if ($body === '') {
            throw FollowUpRefused::emptyNote();
        }

        $note->setBody($body);
        // The thread moved, so the follow-up did. Without this the parent's
        // timestamp would say the last thing that happened here was whatever
        // changed its own fields, which on a busy follow-up is the day it was
        // made.
        $note->getFollowUp()->touch();

        $this->entityManager->flush();
    }

    /**
     * Removes one, if it is yours. Same rule as editing, for the same reason.
     *
     * @throws FollowUpRefused
     */
    public function deleteNote(User $actor, FollowUpNote $note): void
    {
        $this->assertOwnNote($actor, $note);
        $this->assertNotDone($note->getFollowUp());

        $followUp = $note->getFollowUp();
        $followUp->removeNote($note);
        $followUp->touch();

        $this->entityManager->remove($note);
        $this->entityManager->flush();
    }

    /**
     * Archives it, with the moment it happened.
     *
     * @throws FollowUpRefused
     */
    public function markDone(User $actor, FollowUp $followUp): void
    {
        $this->assertMayComplete($actor, $followUp);
        // Not idempotent, deliberately: a second stamp would overwrite the moment
        // this was actually settled, which is the one fact the archive is for.
        $this->assertNotDone($followUp);

        $followUp->markDone(new \DateTimeImmutable());
        $followUp->touch();

        $this->entityManager->flush();
    }

    /**
     * Puts it back on the list.
     *
     * The same permission as marking done, because done is a nullable timestamp
     * rather than a state and these are one edit pointing two ways. Anybody who
     * can close a follow-up they should not have can undo it, which is the
     * property that makes closing safe.
     *
     * @throws FollowUpRefused
     */
    public function reopen(User $actor, FollowUp $followUp): void
    {
        $this->assertMayComplete($actor, $followUp);

        $followUp->reopen();
        $followUp->touch();

        $this->entityManager->flush();
    }

    /** @throws FollowUpRefused */
    private function assertMayComplete(User $actor, FollowUp $followUp): void
    {
        $module = $this->module($followUp->getModule());

        $this->assertMay(
            $actor,
            $module,
            $this->record($module, $followUp->getRecordId()),
            ModuleAction::FollowUpComplete,
        );
    }

    /**
     * The module, if this customer has it and it takes follow-ups.
     *
     * Both failures are refusals rather than exceptions of their own: a module
     * uninstalled between the page being drawn and the form being posted is an
     * ordinary sequence, and so is somebody switching the feature off while a
     * colleague has the record open.
     *
     * @throws FollowUpRefused
     */
    private function module(string $moduleKey): ModuleDefinition
    {
        $module = $this->metadata->find($moduleKey);

        if ($module === null) {
            // The same sentence as a missing record, and deliberately: what the
            // person holding the form needs to know is that the thing they were
            // looking at is not there any more.
            throw FollowUpRefused::noSuchRecord();
        }

        if (!$module->hasFollowUps()) {
            throw FollowUpRefused::notEnabled($module->getLabel());
        }

        return $module;
    }

    /**
     * The record, if it is there and has not been soft-deleted.
     *
     * `find()` excludes deleted rows by default, which is the whole check: there
     * is no foreign key to make a deleted record take its follow-ups with it (see
     * {@see FollowUp}), so this is the moment the absence is noticed.
     *
     * @throws FollowUpRefused
     */
    private function record(ModuleDefinition $module, int $recordId): Record
    {
        return $this->records->find($module, $recordId)
            ?? throw FollowUpRefused::noSuchRecord();
    }

    /**
     * Whether this person holds this verb on this module, and whether their
     * grant reaches this particular record.
     *
     * The scope half goes through {@see RecordAccess::fromPermissions()} rather
     * than comparing owner ids here, which is not ceremony: that is the same
     * object the list's WHERE clause is compiled from, so a person scoped to
     * their own records cannot be shown a list that omits a record and then be
     * allowed to put a follow-up on it by typing its id. Two seams, one rule.
     *
     * @throws FollowUpRefused
     */
    private function assertMay(User $actor, ModuleDefinition $module, Record $record, ModuleAction $action): void
    {
        $access = RecordAccess::fromPermissions(
            $this->permissions->forUser($actor),
            $module->getKey(),
            $action,
            $actor->getId(),
        );

        if ($access->matchesNothing()) {
            throw FollowUpRefused::notPermitted();
        }

        // Restricted with an owner: only that person's records. An owner of null
        // is nobody's rather than everybody's, exactly as the voter and the
        // predicate both read it.
        if ($access->isRestricted() && $record->ownerId !== $access->ownerId()) {
            throw FollowUpRefused::notPermitted();
        }
    }

    /**
     * A follow-up may only be given to somebody who could open the record it sits
     * on.
     *
     * Otherwise a task lands on a list whose owner cannot see what it is about,
     * and the dashboard (XIV-81) is left with two bad options: print the record's
     * title to somebody with no grant for it, or silently hide work that has been
     * assigned to them. Refusing at the point of assignment is the only answer
     * that leaves neither.
     *
     * It goes through the same {@see PermissionResolver} every other check uses,
     * with a user passed in rather than taken from the token — which is exactly
     * why this class takes its actor as a parameter.
     *
     * **Revocation is deliberately not retroactive.** Taking the View grant away
     * afterwards leaves the existing assignment standing: there is no cascade, no
     * cleanup and no listener on grant changes, and that is a decision rather than
     * an omission. A grant is edited on a screen about *people*, and having it
     * silently unassign somebody's outstanding work — with no record of what it
     * did — would make the permission screen a thing nobody dares touch. The
     * residual case is handled where it shows: XIV-81's widget lists such a
     * follow-up without a link to its record.
     *
     * @throws FollowUpRefused
     */
    private function assertAssigneeMayView(ModuleDefinition $module, User $assignee): void
    {
        if (!$this->permissions->forUser($assignee)->allows($module->getKey(), ModuleAction::View)) {
            throw FollowUpRefused::assigneeCannotView($assignee->getName());
        }
    }

    /** @throws FollowUpRefused */
    private function assertOwnNote(User $actor, FollowUpNote $note): void
    {
        if (!$note->isAuthoredBy($actor->getId())) {
            throw FollowUpRefused::notYourNote();
        }
    }

    /**
     * An archived follow-up is history, and history does not change (XIV-85).
     *
     * `done_at` is the whole rule: while it is set, the only thing anybody may do
     * is {@see reopen()}, which is why that method is the one place this is not
     * called. Adding a note, rewriting one, removing one and reassigning all
     * refuse here, and so does marking done something already done — a second
     * stamp would overwrite the moment it actually happened, which is the one
     * fact the archive exists to keep.
     *
     * **Checked here rather than only in the panel**, for the same reason note
     * authorship is (§5.18). The screen not drawing a note box is a courtesy to
     * whoever is looking at it; the rule has to survive a page that was open
     * across somebody else pressing Done, an import, and a console command, none
     * of which have looked at a template.
     *
     * @throws FollowUpRefused
     */
    private function assertNotDone(FollowUp $followUp): void
    {
        if ($followUp->isDone()) {
            throw FollowUpRefused::alreadyDone();
        }
    }

    /**
     * Writes a note onto a thread, without asking anything.
     *
     * The unchecked half of {@see addNote()}, so that {@see create()} can put the
     * first note on a follow-up it has just checked the permission for rather than
     * checking the same thing twice against the same record.
     *
     * @throws FollowUpRefused
     */
    private function write(FollowUp $followUp, User $author, string $body): FollowUpNote
    {
        $body = trim($body);

        if ($body === '') {
            throw FollowUpRefused::emptyNote();
        }

        $note = new FollowUpNote(
            followUp: $followUp,
            body: $body,
            authorId: $this->identify($author),
            authorLabel: $author->getName(),
        );

        $this->entityManager->persist($note);
        $followUp->touch();

        return $note;
    }

    /**
     * A user's id, insisting there is one.
     *
     * `User::getId()` is nullable because an unflushed entity has no id yet, and
     * every caller here has one that came out of the database. Asserting rather
     * than accepting null keeps the columns non-nullable, which is what makes
     * "who wrote this" a question with an answer for every row.
     */
    private function identify(User $user): int
    {
        return $user->getId() ?? throw new \LogicException(
            'A follow-up names a user who has never been saved, which nothing in this application can produce.',
        );
    }
}

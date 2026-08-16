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

namespace App\Controller;

use App\Tenant\Entity\FollowUp;
use App\Tenant\Entity\FollowUpNote;
use App\Tenant\Entity\FollowUpPriority;
use App\Tenant\Entity\User;
use App\Tenant\FollowUp\FollowUpManager;
use App\Tenant\FollowUp\FollowUpRefused;
use App\Tenant\Repository\FollowUpNoteRepository;
use App\Tenant\Repository\FollowUpRepository;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\NoModulePermission;
use App\Tenant\Settings\DisplayTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Permission\ModuleAction;

/**
 * Everything the follow-up panel on a record writes (XIV-82).
 *
 * ### Why there are routes at all, next to a Live Component
 *
 * The panel is a Live Component ({@see \App\Twig\Components\FollowUps}) and every
 * other Live Component in this application owns its own writes —
 * {@see \App\Twig\Components\RecordForm} does the save that used to be a POST to
 * a controller. This one deliberately does not, and the reason is
 * {@see \App\Tests\Functional\Tenant\PermissionCoverageTest}: **the enforcement
 * surface in this application is defined by the URL.** Any route whose path
 * carries `{module}` must name a permission, and any permission that no route
 * names is reported as a control that lies. A `#[LiveAction]` is dispatched
 * through the library's own endpoint at `/_components/…`, which carries no
 * module, so a write living only there would be invisible to the one check that
 * exists because unprotected things are invisible.
 *
 * XIV-80 wrote that expectation down twice while building the engine — at
 * {@see FollowUpManager} and at {@see FollowUpRefused} — as "XIV-82 will still
 * carry `#[IsGranted]` on the routes so the refusal happens before the action
 * runs". This is that. The component keeps what it is good at, which is deciding
 * what is on screen without reloading the page; the writes go through §8.4's
 * first seam, and through the fourth one underneath it.
 *
 * The record page already works this way for its other two mutations: the
 * lifecycle transitions and the delete are plain POST forms with a CSRF token
 * that redirect back to the record. A follow-up marked done is the same kind of
 * act and reads the same way.
 *
 * ### Nothing here decides anything
 *
 * Every rule — the module taking follow-ups at all, the record existing and not
 * being soft-deleted, the grant, the grant's *scope*, a note belonging to its
 * author, an assignee being able to view the record — is
 * {@see FollowUpManager}'s, and this class calls it and catches
 * {@see FollowUpRefused}. Re-checking any of them here would be a second opinion
 * that eventually disagrees. What this class does own is what belongs to HTTP:
 * which route, whose token, which record the URL is about, and where the browser
 * goes next.
 *
 * **One thing is checked here and nowhere else**: that the follow-up named in the
 * path really is on the record named in the path. Without it, somebody holding
 * `follow_up_complete` on `contact` could close an invoice's follow-up by posting
 * its id to a contact URL — the `#[IsGranted]` above votes on the module in the
 * path, and the manager then resolves the module off the *follow-up*, so the two
 * would be talking about different modules and both be satisfied. It is a 404
 * rather than a 403 for §8.4's usual reason: a wrong id and an id belonging to
 * somebody else's record must be indistinguishable.
 *
 * ### Hand-rolled POSTs rather than a FormType
 *
 * Like {@see RecordEmailController}, {@see DocumentController} and the user and
 * field screens: a select, a datetime input and a textarea. The house rule is to
 * reach for Symfony's own component first, and the exception it allows is the
 * administrative screen with three inputs. Where the form component earns its
 * keep is the record form, whose fields come out of a customer's definitions, and
 * it is used there.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}/{id}/follow-ups', requirements: [
    'module' => '[a-z][a-z0-9_]*',
    'id' => Requirement::POSITIVE_INT,
])]
final class FollowUpController extends AbstractController
{
    public function __construct(
        private readonly FollowUpManager $followUps,
        private readonly FollowUpRepository $repository,
        private readonly FollowUpNoteRepository $notes,
        private readonly UserRepository $users,
        private readonly TranslatorInterface $translator,
        // Which clock a typed due date is on (XIV-83). The rendering half needs
        // nothing — the listener sets Twig's zone and `|date` converts — but a
        // date somebody *typed* arrives as wall-clock text with no zone on it,
        // and this is what says whose wall it was.
        private readonly DisplayTimezone $timezones,
    ) {
    }

    /**
     * Opening one, with its first note.
     *
     * The note travels with the creation rather than following it, which is
     * {@see FollowUpManager::create()}'s shape and its argument: "call them back
     * — they asked about the second invoice" is one thought, and two requests
     * would mean a follow-up that exists with nothing written on it whenever the
     * second one was refused.
     */
    #[Route('', name: 'follow_up_open', methods: ['POST'])]
    #[IsGranted(ModuleAction::FollowUpCreate->value, subject: 'module')]
    public function open(string $module, int $id, Request $request): Response
    {
        if (!$this->submitted($request, 'open-follow-up-' . $id)) {
            return $this->back($module, $id);
        }

        $dueAt = $this->dueAt((string) $request->request->get('due_at'));

        if ($dueAt === null) {
            // Only reachable from a hand-made request: the control is
            // `<input type="datetime-local" required>`, so a browser will not
            // send this empty or malformed. Said as a message rather than as a
            // 400 anyway, because being told what is wrong costs nothing and a
            // blank error page teaches nobody anything.
            $this->addFlash('warning', $this->translator->trans('flash.follow_up_no_date'));

            return $this->back($module, $id);
        }

        $this->attempt(function () use ($module, $id, $request, $dueAt): void {
            $this->followUps->create(
                actor: $this->actor(),
                moduleKey: $module,
                recordId: $id,
                priority: FollowUpPriority::tryFrom((string) $request->request->get('priority'))
                    ?? FollowUpPriority::default(),
                dueAt: $dueAt,
                assignee: $this->assignee($request),
                note: (string) $request->request->get('note', ''),
            );

            $this->addFlash('success', $this->translator->trans('flash.follow_up_opened'));
        });

        return $this->back($module, $id);
    }

    /** Adding to the thread. Governed by `follow_up_create`, because a note is what a follow-up is for. */
    #[Route('/{followUp}/notes', name: 'follow_up_note_add', requirements: ['followUp' => Requirement::POSITIVE_INT], methods: ['POST'])]
    #[IsGranted(ModuleAction::FollowUpCreate->value, subject: 'module')]
    public function addNote(string $module, int $id, int $followUp, Request $request): Response
    {
        $found = $this->followUpOn($module, $id, $followUp);

        if ($this->submitted($request, 'note-follow-up-' . $followUp)) {
            $this->attempt(function () use ($found, $request): void {
                $this->followUps->addNote($this->actor(), $found, (string) $request->request->get('note', ''));
            });
        }

        return $this->back($module, $id);
    }

    /**
     * Rewriting one of your own.
     *
     * **No permission, and that is the feature rather than an omission** (§5.18).
     * A note may be edited by whoever wrote it and by nobody else at all,
     * administrators included — the single place in this application where
     * `ROLE_ADMIN` is not a bypass, because editing somebody's sentence under
     * their name is putting words in their mouth. There is therefore no grant to
     * name here: granting one would be saying that somebody else's words are
     * somebody's to rewrite, which is the claim {@see ModuleAction} declines to
     * make.
     *
     * The check is not absent, it is just not a grant:
     * {@see FollowUpManager::editNote()} refuses anything that is not the actor's
     * own, and it resolves the module and the record on the way past so that a
     * note on a deleted record or in a module whose follow-ups were switched off
     * is not editable either.
     */
    #[Route('/{followUp}/notes/{note}', name: 'follow_up_note_edit', requirements: [
        'followUp' => Requirement::POSITIVE_INT,
        'note' => Requirement::POSITIVE_INT,
    ], methods: ['POST'])]
    #[NoModulePermission(
        'A note belongs to whoever wrote it and to nobody else, with no administrator override (§5.18). '
        . 'There is no grant that could govern this, so FollowUpManager::editNote() refuses on authorship instead.',
    )]
    public function editNote(string $module, int $id, int $followUp, int $note, Request $request): Response
    {
        $found = $this->noteOn($module, $id, $followUp, $note);

        if ($this->submitted($request, 'edit-note-' . $note)) {
            $this->attempt(function () use ($found, $request): void {
                $this->followUps->editNote($this->actor(), $found, (string) $request->request->get('note', ''));
            });
        }

        return $this->back($module, $id);
    }

    /** Removing one of your own. The same rule as editing, for the same reason. */
    #[Route('/{followUp}/notes/{note}/delete', name: 'follow_up_note_delete', requirements: [
        'followUp' => Requirement::POSITIVE_INT,
        'note' => Requirement::POSITIVE_INT,
    ], methods: ['POST'])]
    #[NoModulePermission(
        'The other half of follow_up_note_edit: authorship, never a grant (§5.18).',
    )]
    public function deleteNote(string $module, int $id, int $followUp, int $note, Request $request): Response
    {
        $found = $this->noteOn($module, $id, $followUp, $note);

        if ($this->submitted($request, 'delete-note-' . $note)) {
            $this->attempt(fn () => $this->followUps->deleteNote($this->actor(), $found));
        }

        return $this->back($module, $id);
    }

    /** Into the archive. */
    #[Route('/{followUp}/done', name: 'follow_up_done', requirements: ['followUp' => Requirement::POSITIVE_INT], methods: ['POST'])]
    #[IsGranted(ModuleAction::FollowUpComplete->value, subject: 'module')]
    public function done(string $module, int $id, int $followUp, Request $request): Response
    {
        $found = $this->followUpOn($module, $id, $followUp);

        if ($this->submitted($request, 'done-follow-up-' . $followUp)) {
            $this->attempt(fn () => $this->followUps->markDone($this->actor(), $found));
        }

        return $this->back($module, $id);
    }

    /**
     * And back out of it.
     *
     * The same grant as marking done, which is §5.18's argument rather than
     * carelessness: done is a nullable timestamp and not a state, so these are
     * one edit pointing two ways, and anybody who can close a follow-up they
     * should not have can undo it. That property is what makes closing safe.
     */
    #[Route('/{followUp}/reopen', name: 'follow_up_reopen', requirements: ['followUp' => Requirement::POSITIVE_INT], methods: ['POST'])]
    #[IsGranted(ModuleAction::FollowUpComplete->value, subject: 'module')]
    public function reopen(string $module, int $id, int $followUp, Request $request): Response
    {
        $found = $this->followUpOn($module, $id, $followUp);

        if ($this->submitted($request, 'reopen-follow-up-' . $followUp)) {
            $this->attempt(fn () => $this->followUps->reopen($this->actor(), $found));
        }

        return $this->back($module, $id);
    }

    // -- the plumbing -------------------------------------------------------

    /**
     * Runs one write and turns a refusal into a sentence.
     *
     * Every refusal this feature can produce is one {@see FollowUpRefused}, which
     * was XIV-80's decision and is the whole reason this method is three lines: a
     * form post has to handle "you lack the grant", "that record is gone" and
     * "somebody switched follow-ups off while you had the page open" alike, and
     * three exception types would be three catch blocks of which the least
     * likely one eventually becomes a 500.
     *
     * A *warning* rather than an error, because nearly every one of these is a
     * page that went stale rather than somebody doing something wrong.
     *
     * @param callable():void $write
     */
    private function attempt(callable $write): void
    {
        try {
            $write();
        } catch (FollowUpRefused $refusal) {
            $this->addFlash('warning', $refusal->translatable()->trans($this->translator));
        }
    }

    /**
     * The follow-up the path names, insisting it is on the record the path names.
     *
     * See the class docblock: this is the one rule that is this class's and not
     * the manager's, because the manager resolves the module from the follow-up
     * row while `#[IsGranted]` votes on the module in the URL. Nothing else
     * notices when those two disagree.
     */
    private function followUpOn(string $module, int $recordId, int $followUpId): FollowUp
    {
        $followUp = $this->repository->find($followUpId);

        if ($followUp === null || $followUp->getModule() !== $module || $followUp->getRecordId() !== $recordId) {
            throw $this->createNotFoundException();
        }

        return $followUp;
    }

    /** The same, one level down: a note has to be on the follow-up the path names. */
    private function noteOn(string $module, int $recordId, int $followUpId, int $noteId): FollowUpNote
    {
        $followUp = $this->followUpOn($module, $recordId, $followUpId);
        $note = $this->notes->find($noteId);

        if ($note === null || $note->getFollowUp() !== $followUp) {
            throw $this->createNotFoundException();
        }

        return $note;
    }

    /**
     * A typed date, read on the clock of whoever typed it.
     *
     * `due_at` is `timestamptz` (§5.18) because a deadline is an instant two
     * people in two countries have to agree about — and `datetime-local` sends
     * exactly the opposite, a wall-clock reading with no zone on it at all. So
     * the reader's zone is supplied here, which is the mirror image of XIV-83's
     * rendering rule: the display half needs no conversion because the listener
     * has already told Twig which clock to print on, and this half needs one
     * because the browser did not say which clock it read from.
     *
     * Getting this wrong is invisible in the country the server happens to sit
     * in and an hour or nine out everywhere else, which is precisely the failure
     * XIV-83 was about.
     *
     * Null for anything unparseable, including empty. `DateTimeImmutable`
     * accepts a startling range of English — "now", "next friday" — and none of
     * it can arrive from a `datetime-local` control, so the format is asserted
     * rather than trusted: a hand-made request saying "tomorrow" is not a date
     * this application agreed to store.
     */
    private function dueAt(string $typed): ?\DateTimeImmutable
    {
        $zone = $this->timezones->of($this->getUser() instanceof User ? $this->getUser() : null);

        // Seconds are optional in what a browser sends: Firefox omits them, and
        // Chrome includes them when the control carries a step. Both are the
        // same kind of moment and both have to parse.
        //
        // **Longest first, and that ordering is load-bearing.**
        // `createFromFormat` does not mind trailing characters — it warns and
        // hands back a value — so trying the short format first would swallow
        // `…T09:00:30` and quietly drop the seconds. The long one simply fails
        // on a string that has none, which is the refusal that makes the order
        // work.
        //
        // The leading `!` resets everything the format does not mention. Without
        // it the unparsed fields default to *now*, so a deadline typed as 09:00
        // would be stored as 09:00:37 — harmless in a diary and untidy in every
        // export of one.
        foreach (['!Y-m-d\TH:i:s', '!Y-m-d\TH:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $typed, $zone);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Who it is for, or nobody.
     *
     * The empty option is a real answer rather than a missing one (§5.18): a
     * follow-up on a record is a note the office keeps, and forcing an assignee
     * on it would make "somebody should call them back" into an accusation.
     *
     * An id naming nobody is treated as nobody rather than as an error. Whether
     * the person named may actually be given this follow-up is not decided here
     * — {@see FollowUpManager::assertAssigneeMayView()} decides it, and
     * {@see \App\Tenant\FollowUp\FollowUpAssignees} is what keeps the picker from
     * offering somebody it would refuse.
     */
    private function assignee(Request $request): ?User
    {
        $id = $request->request->getInt('assignee');

        return $id > 0 ? $this->users->find($id) : null;
    }

    /**
     * Whoever is doing this, as the thing the manager wants.
     *
     * The manager takes its actor as a parameter rather than reading the token
     * (§5.18), so somebody has to supply one, and on this path it is always the
     * signed-in user. Anything else is impossible — the firewall covers
     * `/m/{module}` — so it is an assertion rather than a branch.
     */
    private function actor(): User
    {
        $user = $this->getUser();

        \assert($user instanceof User);

        return $user;
    }

    /** A token, checked the way the record page's other two POSTs check theirs. */
    private function submitted(Request $request, string $token): bool
    {
        return $this->isCsrfTokenValid($token, (string) $request->request->get('_token'));
    }

    /**
     * Back to the record, at the panel.
     *
     * The fragment matters more than it looks on a long record: the panel is at
     * the top of the page, so an anchor is what makes "mark done" leave the
     * browser looking at the thing that changed rather than wherever it happened
     * to be scrolled to.
     */
    private function back(string $module, int $id): Response
    {
        return $this->redirectToRoute(
            'module_show',
            ['module' => $module, 'id' => $id, '_fragment' => 'follow-ups'],
        );
    }
}

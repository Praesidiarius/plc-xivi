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

namespace App\Twig\Components;

use App\Tenant\Entity\FollowUp;
use App\Tenant\Entity\FollowUpNote;
use App\Tenant\Entity\FollowUpPriority;
use App\Tenant\Entity\User;
use App\Tenant\FollowUp\FollowUpAssignees;
use App\Tenant\Repository\FollowUpRepository;
use App\Tenant\Settings\DisplayTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * What is outstanding on one record, at the top of its page (XIV-82).
 *
 * ### What is live here, and what deliberately is not
 *
 * **Nothing this component does writes anything.** Every mutation is a plain
 * POST to {@see \App\Controller\FollowUpController}, which says at length why:
 * the permission check that guards this feature has to be visible to a test that
 * reads *routes*, and a `#[LiveAction]` has no route with a module in it. That is
 * a departure from {@see RecordForm}, which owns its own save, and the departure
 * is the point rather than an inconsistency — the record form is protected by the
 * `module_edit` route it is mounted on, and there is no `follow_up_create` route
 * unless this feature makes one.
 *
 * What is left for the component is what a component is actually for: **deciding
 * what is on the page without fetching the page again.** Three pieces of state,
 * and each earns its keep by keeping markup out of a document rather than by
 * hiding it with CSS:
 *
 *  * `showDone` — the archive. A record with forty settled follow-ups would
 *    otherwise render forty cards and their entire note threads into a page that
 *    is about the record, and hide them behind a `<details>`. The count is
 *    cheap; the markup is not, and the ticket's complaint is exactly that the
 *    record's own fields must not be pushed off screen.
 *  * `adding` — the create form, whose assignee picker is a permission
 *    resolution per user in the tenant ({@see FollowUpAssignees}). Most records
 *    never get a follow-up, and paying for that list on every record page to
 *    fill a form nobody opened is the one cost here worth avoiding.
 *  * `editing` — which note is being rewritten. One textarea at a time, so the
 *    thread reads as a thread rather than as a page of forms.
 *
 * A `<details>` would have done the disclosure with no JavaScript at all, and the
 * linked-record cards on this very page use one for that reason. The difference
 * is that a `<details>` still has to be *sent* the content it is hiding.
 *
 * ### The whole panel disappears when the module does not take follow-ups
 *
 * Not an empty state, not a counter reading zero, not a heading: nothing. §5.18
 * made the switch reversible on purpose, and a customer who has turned the
 * feature off is entitled to a page with no trace of it — a box saying "no
 * follow-ups" is the feature refusing to leave. The record page also asks before
 * it mounts this at all, so in the ordinary case nothing here is even
 * constructed; the check below is for the page that was open across the moment
 * somebody switched it off.
 *
 * ### Reading is `view`, and it is checked here as well as at the route
 *
 * A follow-up says nothing the record does not already say to whoever may open it
 * (§5.18), so there is no read grant and the module's own View is the whole
 * rule. The record page has already voted on it — but props are signed rather
 * than secret, and a component is reachable at its own endpoint, so this asks
 * again. It costs one cached lookup and removes the need to reason about whether
 * the only page that mounts it is the only way in.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsLiveComponent('FollowUps')]
final class FollowUps extends AbstractController
{
    use DefaultActionTrait;

    /** Scalars only: a prop is a signed attribute in the page, and travels as JSON. */
    #[LiveProp]
    public string $module = '';

    #[LiveProp]
    public int $recordId = 0;

    /** Whether the archive has been asked for. Closed on arrival, always. */
    #[LiveProp]
    public bool $showDone = false;

    /** Whether the "what needs doing" form is open. */
    #[LiveProp]
    public bool $adding = false;

    /** Which note is being rewritten, if any. One at a time, by construction. */
    #[LiveProp]
    public ?int $editing = null;

    /**
     * This record's follow-ups, read once however many times the template asks.
     *
     * The template asks three times — the open ones, how many done ones there
     * are, and the done ones themselves — and each of those would otherwise be a
     * query plus the soft-delete check {@see FollowUpRepository} has to make
     * after it. Not a cache with a lifetime: a component is built, rendered and
     * thrown away inside one request, so this is only "twice in one render is
     * once".
     *
     * @var list<FollowUp>|null
     */
    private ?array $followUps = null;

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly FollowUpRepository $repository,
        private readonly FollowUpAssignees $assignees,
        // The clock the form's date control should start on (XIV-83). Rendering
        // needs nothing — Twig has been told the zone — but a default *value*
        // for an input is a string this has to write, and writing it in UTC
        // would offer a Tokyo office tomorrow morning at six.
        private readonly DisplayTimezone $timezones,
    ) {
    }

    /** @return list<FollowUp> */
    public function getActive(): array
    {
        return array_values(array_filter($this->all(), static fn (FollowUp $f): bool => !$f->isDone()));
    }

    /**
     * The settled ones — read only once somebody has asked for them.
     *
     * The query behind this has already run either way (they come back in the
     * same call as the open ones, ordered by the repository so that open comes
     * first). What the flag saves is the *rendering*: forty cards, forty note
     * threads and forty reopen buttons that nobody asked to see.
     *
     * @return list<FollowUp>
     */
    public function getArchived(): array
    {
        if (!$this->showDone) {
            return [];
        }

        return array_values(array_filter($this->all(), static fn (FollowUp $f): bool => $f->isDone()));
    }

    /** How many there are in the archive, whether or not it is open. */
    public function getDoneCount(): int
    {
        return \count(array_filter($this->all(), static fn (FollowUp $f): bool => $f->isDone()));
    }

    /**
     * Whether this customer's copy of this module takes follow-ups at all.
     *
     * A module that has been uninstalled answers false rather than throwing, for
     * the same reason {@see \App\Tenant\FollowUp\ModuleFollowUps::enabledFor()}
     * does: a page open across somebody uninstalling a module is an ordinary
     * sequence and not an error, and this component's answer to every kind of
     * "not applicable" is the same — draw nothing.
     */
    public function isEnabled(): bool
    {
        return $this->metadata->find($this->module)?->hasFollowUps() ?? false;
    }

    /**
     * The module, for the template's permission questions.
     *
     * `can('follow_up_create', module, record)` needs the definition and the row,
     * because both of these verbs are scopable (§8.4): somebody granted them for
     * their own records only must not be offered the buttons on a colleague's.
     * The manager refuses that anyway; this is what keeps the refusal from being
     * how they find out.
     */
    public function getModuleDefinition(): ModuleDefinition
    {
        return $this->definition();
    }

    public function getRecord(): Record
    {
        return $this->recordOf($this->definition());
    }

    /**
     * Who may be given one.
     *
     * Only built when the form is open — see the class docblock; this is a
     * permission resolution per user in the tenant, and it is the one thing here
     * that costs something.
     *
     * @return list<User>
     */
    public function getAssignees(): array
    {
        return $this->adding ? $this->assignees->forModule($this->module) : [];
    }

    /** @return list<FollowUpPriority> */
    public function getPriorities(): array
    {
        return FollowUpPriority::cases();
    }

    /**
     * Which one the picker opens on.
     *
     * Asked of the enum rather than written into the template, because
     * {@see FollowUpPriority::default()} has an argument attached — guessing
     * upward would be the mistake, since a system where everything arrives
     * marked important is one where nothing is — and a template hard-coding
     * `info` would be a second place holding that opinion, silently, until the
     * two disagreed.
     */
    public function getDefaultPriority(): FollowUpPriority
    {
        return FollowUpPriority::default();
    }

    /**
     * Whether this note is the reader's to change.
     *
     * The only thing that decides which notes carry edit and delete. It asks the
     * note rather than comparing anything, because {@see FollowUpNote} owns that
     * question and the manager refuses on exactly the same call — so the buttons
     * on screen and the rule underneath them cannot drift apart.
     *
     * Hiding is a courtesy and never a control: somebody who posts the edit form
     * of a colleague's note is refused by {@see \App\Tenant\FollowUp\FollowUpManager},
     * with no administrator override anywhere in the path.
     */
    public function isMine(FollowUpNote $note): bool
    {
        $user = $this->getUser();

        return $user instanceof User && $note->isAuthoredBy($user->getId());
    }

    /**
     * What the date control starts on: tomorrow morning, on the reader's clock.
     *
     * A follow-up is nearly always about a day that is not today — "call them
     * back on Friday" — so an empty control would make every single one of these
     * a date somebody has to type. Tomorrow at nine is a guess, and it is a guess
     * in the direction that costs least: it is visible in the field before
     * anybody presses anything, so being wrong about it costs one correction
     * rather than a wrong deadline nobody noticed.
     *
     * Formatted the way `<input type="datetime-local">` wants it, which is the
     * same wall-clock-without-a-zone the control sends back and
     * {@see \App\Controller\FollowUpController::dueAt()} reads on the same zone.
     */
    public function getDefaultDueAt(): string
    {
        return (new \DateTimeImmutable('tomorrow 09:00', $this->displayZone()))->format('Y-m-d\TH:i');
    }

    #[LiveAction]
    public function revealArchive(): void
    {
        $this->showDone = true;
    }

    #[LiveAction]
    public function hideArchive(): void
    {
        $this->showDone = false;
    }

    #[LiveAction]
    public function startAdding(): void
    {
        $this->adding = true;
    }

    #[LiveAction]
    public function cancelAdding(): void
    {
        $this->adding = false;
    }

    /**
     * Rewriting one note.
     *
     * The id is not validated against anything here, and does not need to be: it
     * decides which textarea is drawn, and a made-up one draws none. What
     * happens when the form is posted is the manager's, and the manager asks
     * whose note it is.
     */
    #[LiveAction]
    public function startEditing(#[LiveArg] int $note): void
    {
        $this->editing = $note;
    }

    #[LiveAction]
    public function cancelEditing(): void
    {
        $this->editing = null;
    }

    /**
     * Everything on this record, open ones first.
     *
     * @return list<FollowUp>
     */
    private function all(): array
    {
        // Through the definition rather than straight to the repository, because
        // that is where the read check lives and this is the other of the two
        // doors into the data.
        $this->definition();

        return $this->followUps ??= $this->repository->forRecord($this->module, $this->recordId);
    }

    /**
     * The module, having checked that whoever is asking may read this record's
     * module at all.
     *
     * Every public accessor above funnels through here or through {@see all()},
     * which is deliberate: one place to make the check means there is no
     * accessor that can be added later without it.
     */
    private function definition(): ModuleDefinition
    {
        $this->denyAccessUnlessGranted(ModuleAction::View->value, $this->module);

        return $this->metadata->get($this->module);
    }

    private function recordOf(ModuleDefinition $definition): Record
    {
        return $this->records->find($definition, $this->recordId) ?? throw $this->createNotFoundException();
    }

    private function displayZone(): \DateTimeZone
    {
        $user = $this->getUser();

        return $this->timezones->of($user instanceof User ? $user : null);
    }
}

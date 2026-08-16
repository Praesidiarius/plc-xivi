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
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;

/**
 * One line of the dashboard widget: a follow-up, and the record it is about when
 * the reader is allowed to know (XIV-81).
 *
 * **The record is optional, and that pairing is the whole reason this class
 * exists.** XIV-80 refuses to *assign* a follow-up to somebody who cannot view
 * its record, so the honest case is rare — but revoking the grant afterwards is
 * deliberately not retroactive (§5.18), because a screen about people must not
 * silently unassign somebody's outstanding work. The residue lands here: the
 * follow-up's own text, due moment and priority are the reader's, since they were
 * given the task; the record's title and a link to it are not, since they may no
 * longer open it. They keep the work and do not learn what it is attached to.
 *
 * That is the same split XIV-42 made between a reference's *name* and a *link* to
 * it, arrived at from the other direction — there the name is shown to everybody
 * and only the link is decided by permission, because whoever can see the
 * referring record can already see what it refers to. Here nothing about the
 * record has been shown yet, so the name goes with the link.
 *
 * **A follow-up on a soft-deleted record is not this case and never reaches
 * here**: it is excluded outright by {@see \App\Tenant\Repository\FollowUpRepository},
 * because there is nothing to open and no cascade to have removed it (§5.18).
 * "Shown without a link" and "not shown" are different answers to different
 * questions, and conflating them would put work about a customer somebody deleted
 * last month on the landing page.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class AssignedFollowUp
{
    /**
     * @param ModuleDefinition|null $module the module the record belongs to, or
     *                                      null when the reader may not view it
     * @param Record|null           $record the record itself, null with `$module`
     *                                      and never without it — the two are one
     *                                      answer to one question, which is why
     *                                      {@see namesItsRecord()} is what
     *                                      templates ask rather than either
     *                                      property being tested on its own
     */
    public function __construct(
        public FollowUp $followUp,
        public ?ModuleDefinition $module = null,
        public ?Record $record = null,
    ) {
    }

    /** Whether this line may say what it is about, and link there. */
    public function namesItsRecord(): bool
    {
        return $this->module !== null && $this->record !== null;
    }

    /**
     * What the follow-up says, which is the note it was opened with.
     *
     * A follow-up carries a priority, a date and a thread, and no sentence of its
     * own — {@see FollowUpManager::create()} folds the first
     * note into creating one precisely because "call them back — they asked about
     * the second invoice" is one thought. So the opening note *is* the follow-up's
     * text, and it is the half that stays visible when the record does not
     * (§5.18): a line reading only "Important, due Thursday" would be a task
     * stripped of what it was.
     *
     * The *first* rather than the last, deliberately. Later notes are progress on
     * the question; the first one is the question, and a widget somebody glances
     * at wants to know what the job is rather than what was last said about it.
     *
     * Null for a follow-up opened without a note, which is allowed — the record it
     * sits on is the context in that case, and the line falls back to naming that.
     *
     * Costs no query: {@see \App\Tenant\Repository\FollowUpRepository::openFor()}
     * fetch-joins the thread for exactly this.
     */
    public function openingNote(): ?FollowUpNote
    {
        return $this->followUp->getNotes()->first() ?: null;
    }
}

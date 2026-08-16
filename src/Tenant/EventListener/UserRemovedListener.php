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

namespace App\Tenant\EventListener;

use App\Tenant\Entity\User;
use App\Tenant\Repository\FollowUpRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * A departing user leaves their follow-ups standing, and stops being on them
 * (XIV-80).
 *
 * **This is the foreign key that is deliberately not there.** `assignee_id`
 * carries no constraint — see {@see \App\Tenant\Entity\FollowUp} for the argument
 * — so `ON DELETE SET NULL` cannot do this, and nothing in Postgres will notice
 * that the row it points at has gone. Without this listener a deleted user would
 * leave follow-ups assigned to an id nobody can resolve, which is the quiet
 * version of the failure: the work would still be in the table and would appear
 * on nobody's list.
 *
 * **The label stays.** Clearing the assignment is not the same as unassigning
 * (the entity has both methods for exactly this reason): a follow-up that says
 * "was Marta Beck's" is still the best clue anybody has about who was going to do
 * it, and blanking the name would lose that at the moment it becomes most useful.
 *
 * **Only the assignment, not `created_by_id`.** Who made a follow-up is a fact
 * about something that happened, the same kind of fact as `<module>_history`'s
 * `user_id`, and rewriting it when somebody leaves would be rewriting the past.
 * The assignee is different in kind: it is a live claim on a person's attention,
 * and a person who is gone has none.
 *
 * **`preRemove` rather than `postRemove`, and that is not a preference.** After
 * the DELETE, Doctrine writes null over the identifier of any entity whose id was
 * generated — so by `postRemove` the object no longer knows which user it was,
 * and a listener reading `getId()` there is handed null and quietly does nothing.
 * That is precisely how this was written first, and it passed review by reading
 * correctly: the sequence is invisible unless you know it or test it. Running
 * before the DELETE also keeps this inside the same transaction, so a failure
 * takes the removal with it rather than leaving follow-ups let go of by a user
 * who is still there.
 *
 * **Users are deactivated rather than deleted** (§8.4.1), so in the application
 * as it stands nothing reaches this. That is on purpose rather than a reason not
 * to have it: a tenant's data can be edited by a console command and a delete
 * button is one ticket away, and the failure this prevents is invisible when it
 * happens. Deactivating deliberately does *not* fire it — a deactivated colleague
 * is coming back from leave, and their list should still be theirs.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEntityListener(event: Events::preRemove, entity: User::class, entityManager: 'tenant')]
final readonly class UserRemovedListener
{
    public function __construct(
        private FollowUpRepository $followUps,
    ) {
    }

    public function __invoke(User $user): void
    {
        $id = $user->getId();

        // Never null in practice — an entity that was never saved cannot be
        // removed — but see the class docblock for why the identifier would be
        // gone one event later.
        if ($id === null) {
            return;
        }

        // A bulk update, which means it does not reach entities already loaded in
        // this unit of work: a FollowUp fetched earlier in the same request would
        // still hold the old assignee in memory until it is refreshed. Accepted,
        // because deleting a user and reading follow-ups in one request is not a
        // sequence anything does, and the alternative — loading every affected
        // follow-up to null one field — is a page of work for that hypothetical.
        $this->followUps->clearAssignee($id);
    }
}

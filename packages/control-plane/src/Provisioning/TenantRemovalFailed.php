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

namespace Xivi\ControlPlane\Provisioning;

/**
 * A `tenant:deprovision` that stopped part-way, and what it left standing (XIV-94).
 *
 * ## Why removal gets an exception of its own
 *
 * `ProvisioningFailed` is a family of refusals: a slug that is not a valid
 * identifier, a hostname somebody else owns, a database that already exists.
 * Every one of them is raised *before* anything is created, so the answer is
 * always the same — nothing happened, fix the input, type it again. Removal has
 * no such luxury. It destroys three cluster objects in sequence, none of it
 * transactional, and a failure at the second one leaves a state that is neither
 * "before" nor "after". The one thing an operator needs at that moment is which
 * of the three are gone, and a refusal class whose whole contract is "nothing
 * happened" cannot carry it.
 *
 * So this exception carries the state instead of describing the cause twice: the
 * message says what went wrong *and* what is standing, so a caller that lets it
 * fly — `tenant:reset` does, deliberately, see §4.1 — is still honest without
 * writing a report of its own, while `tenant:deprovision` unpacks the same facts
 * into a definition list because it has a terminal to draw one on.
 *
 * ## Order is what makes the state describable
 *
 * The state below is only ever one of four, because the drops happen in a fixed
 * order and the control-plane row goes **last**: everything standing, database
 * gone, database and role gone, or all three gone (which is success and not an
 * exception). Until XIV-94 the row went first, which produced a fifth state —
 * database and role standing with no row naming them — and that one is the only
 * one nobody can act on, because every tool that would clean it up starts from
 * the registry. §4.1 carries the argument; this class is the part of it that an
 * operator reads.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantRemovalFailed extends \RuntimeException
{
    /**
     * @param string $reason          one sentence about what went wrong, in words an operator
     *                                already has — the half `tenant:deprovision` puts in its error
     *                                block, which is why the driver's own phrasing is kept out of it
     * @param bool   $databaseDropped the tenant's database is gone for good
     * @param bool   $roleDropped     the tenant's Postgres role is gone
     * @param bool   $filesRemoved    the tenant's attachment directory is gone ([XIV-115]).
     *                                Defaults to false because it is removed after the role and
     *                                before the registry row, so every failure written before this
     *                                parameter existed is a failure that stopped while the files
     *                                were still there, and says so without being edited
     * @param string $detail          the cause in the database's own words, for the cases where the
     *                                reason cannot name it — a dependent object, a lock, a disk. It
     *                                joins `getMessage()` and not `reason()`, because whoever finds
     *                                this in a log has no `getPrevious()` to unwrap and whoever is
     *                                reading a terminal is shown it separately, underneath
     */
    private function __construct(
        private readonly string $reason,
        public readonly string $slug,
        public readonly string $database,
        public readonly string $role,
        public readonly bool $databaseDropped,
        public readonly bool $roleDropped,
        public readonly bool $filesRemoved = false,
        ?\Throwable $previous = null,
        string $detail = '',
    ) {
        parent::__construct(
            rtrim($reason . ' ' . $detail) . ' ' . $this->stateSentence(),
            0,
            $previous,
        );
    }

    /**
     * Something is still attached and we were not allowed to detach it.
     *
     * Postgres will only let a session be terminated by a superuser, by a member
     * of the built-in `pg_signal_backend` role, or by a role that holds the
     * privileges of the role whose session it is — and "holds the privileges of"
     * means an *inherited* membership. That last clause is the one that catches
     * people out, because `CREATE ROLE` run by a `CREATEROLE` role grants the
     * creator `ADMIN` on the new role but neither `INHERIT` nor `SET` since
     * Postgres 16, so a provisioning role that made every tenant role on the
     * cluster still may not signal a single one of their backends. Measured on
     * this project's own Postgres 18 rather than read off a manual page; §4.1
     * records the experiment.
     *
     * Nothing has been destroyed when this is raised, which is why the remedy is
     * a grant and a re-run rather than anything to clean up by hand.
     *
     * **The grant this names does not cover one case, and that case cannot
     * happen here.** `pg_signal_backend` explicitly excludes backends belonging
     * to superusers — measured, by pointing the provisioning DSN at a narrowed
     * role and leaving a superuser `psql` attached, which still refuses with
     * "Only roles with the SUPERUSER attribute may terminate processes of roles
     * with the SUPERUSER attribute". A tenant's sessions are its own role's, and
     * a tenant role is never a superuser (§4 revokes even `CONNECT` from PUBLIC
     * for exactly that reason), so the advice is correct for every session this
     * command is ever asked to end. A superuser sitting in a tenant's database
     * with `psql` is an operator, and an operator can close their own window.
     */
    public static function mayNotDisconnectSessions(
        string $slug,
        string $database,
        string $role,
        int $sessions,
        string $adminRole,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'Something is still connected to tenant "%s": %s to database "%s", and the '
                . 'provisioning role "%s" is not allowed to disconnect them. Postgres grants that '
                . 'only to a superuser, to a member of pg_signal_backend, or to a role that inherits '
                . 'the privileges of the connected role — so run '
                . '"GRANT pg_signal_backend TO %s" as a superuser and try again.',
                $slug,
                self::countSessions($sessions),
                $database,
                $adminRole,
                $adminRole,
            ),
            $slug,
            $database,
            $role,
            databaseDropped: false,
            roleDropped: false,
            previous: $previous,
        );
    }

    /**
     * We disconnected everything we could see, and Postgres still refused.
     *
     * The expected case after the terminate is a race: a client that reconnected
     * in the moment between the two statements — an application container
     * restarting, a connection pool refilling, a second operator. The drop is
     * issued `WITH (FORCE)` precisely so that window is small, but small is not
     * zero, and a reconnecting client can win it indefinitely if it is
     * reconnecting in a loop. Which is why the advice here is to stop *that*
     * rather than to run this command harder.
     *
     * **It is not quite the only case, and this docblock used to say it was**
     * (XIV-142). `pg_terminate_backend` sends SIGTERM and returns as soon as the
     * signal is away; the backend leaves `pg_stat_activity` a moment later, when
     * it next checks for interrupts. So a session can still be attached when the
     * drop is issued without anything having reconnected. That does not normally
     * reach here, because `WITH (FORCE)` signals what is left and then *waits* up
     * to five seconds for it to detach — measured on this cluster at 5005 ms
     * before it gives up, with a backend deliberately held under SIGSTOP. A
     * backend that has not gone after five seconds of being asked twice is stuck
     * rather than slow.
     *
     * The sentence below is left claiming a reconnect anyway, and that is a
     * deliberate trade rather than an oversight. It is right about the case that
     * happens and it points at the action that fixes it; hedging it would cost
     * every operator who meets the ordinary failure a sentence of "or possibly
     * something else" in exchange for a case this project has only ever produced
     * with `kill -STOP`. What is owed instead is this paragraph, so that an
     * operator who follows the advice and finds nothing dialling the database
     * knows there is a second thing to look for: a backend that has stopped
     * running rather than a client that keeps starting.
     */
    public static function sessionsCameBack(
        string $slug,
        string $database,
        string $role,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'Something reconnected to database "%s" while tenant "%s" was being removed, so '
                . 'Postgres refused to drop it. Every session that existed a moment earlier was '
                . 'disconnected, so this is a client reconnecting rather than one that was never '
                . 'closed: stop whatever is dialling that database — an application container, a '
                . 'pooler, another operator — and run this again.',
                $database,
                $slug,
            ),
            $slug,
            $database,
            $role,
            databaseDropped: false,
            roleDropped: false,
            previous: $previous,
        );
    }

    /** The drop failed for a reason that is neither a session nor a permission to end one. */
    public static function databaseSurvived(
        string $slug,
        string $database,
        string $role,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf('Database "%s" could not be dropped.', $database),
            $slug,
            $database,
            $role,
            databaseDropped: false,
            roleDropped: false,
            previous: $previous,
            detail: $previous->getMessage(),
        );
    }

    /**
     * The database is gone and its role is not.
     *
     * Worth its own state because the two objects fail for unrelated reasons: a
     * role survives its database when it still owns something elsewhere on the
     * cluster, or when somebody granted it to another role. Nothing routes to the
     * tenant any more at this point — the database is what held the data — so
     * this is untidiness rather than an outage, and saying which of the two it is
     * is the whole difference between an operator finishing the job and an
     * operator wondering what they just half-did.
     */
    public static function roleSurvived(
        string $slug,
        string $database,
        string $role,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'The database of tenant "%s" is gone, but its Postgres role "%s" could not be dropped.',
                $slug,
                $role,
            ),
            $slug,
            $database,
            $role,
            databaseDropped: true,
            roleDropped: false,
            previous: $previous,
            detail: $previous->getMessage(),
        );
    }

    /**
     * Both cluster objects are gone and the registry still names them.
     *
     * The mirror image of the failure XIV-94 was written about, and the reason
     * the order was turned around: this state is *recoverable by re-running the
     * same command*, because both drops are `IF EXISTS` and will pass straight
     * over what is no longer there before removing the row. The state it replaced
     * — no row, database still standing — was recoverable only by somebody who
     * already knew the database name, and the row that would have told them was
     * the thing that had just been deleted.
     */
    public static function registryRowSurvived(
        string $slug,
        string $database,
        string $role,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf('Tenant "%s" was removed from the cluster but not from the control plane.', $slug),
            $slug,
            $database,
            $role,
            databaseDropped: true,
            roleDropped: true,
            filesRemoved: true,
            previous: $previous,
            detail: $previous->getMessage(),
        );
    }

    /**
     * The cluster is empty and the files are not ([XIV-115]).
     *
     * A state of its own because it is the one the ordering was chosen to make
     * survivable. The attachment directory is removed **after** the database and
     * the role and **before** the control-plane row, and each half of that is a
     * decision:
     *
     *  * after the drops, because a removal that deleted a live customer's files
     *    and then failed to drop their database would have destroyed data while
     *    the tenant was still serving requests;
     *  * before the row, because the directory's name is derived from the DSN in
     *    that row (§5.30). Delete the row first and the files are still on the
     *    disk with nothing left that can work out where.
     *
     * So this is recoverable exactly the way §4.1 says every removal state must
     * be: the row still names the tenant, and running the same command again
     * steps over the drops and finishes the job.
     */
    public static function filesSurvived(
        string $slug,
        string $database,
        string $role,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'The database and role of tenant "%s" are gone, but its files could not be removed.',
                $slug,
            ),
            $slug,
            $database,
            $role,
            databaseDropped: true,
            roleDropped: true,
            filesRemoved: false,
            previous: $previous,
            detail: $previous->getMessage(),
        );
    }

    /** What went wrong, with no state in it. {@see getMessage()} is this plus {@see stateSentence()}. */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The three things this command destroys, and what became of each.
     *
     * Shaped for `SymfonyStyle::definitionList()` because that is the only caller
     * with somewhere to draw it, but the wording is deliberately readable on its
     * own: whoever ends up reading this out of a log has the same question as
     * whoever read it off a terminal.
     *
     * @return list<array<string, string>>
     */
    public function state(): array
    {
        return [
            ['Database' => $this->databaseDropped
                ? sprintf('%s — dropped. Gone for good; only a backup has it now.', $this->database)
                : sprintf('%s — still there, with everything in it.', $this->database)],
            ['Role' => $this->roleDropped
                ? sprintf('%s — dropped.', $this->role)
                : sprintf('%s — still there.', $this->role)],
            ['Files' => $this->filesRemoved
                ? 'removed.'
                : 'still on the disk. The control-plane row below is what says where.'],
            ['Control-plane row' => sprintf(
                'still there. "bin/console tenant:list" still shows "%s".',
                $this->slug,
            )],
        ];
    }

    /**
     * What to type next.
     *
     * Always the same line, and that is the point rather than a shortcut: the
     * order the removal runs in was chosen so that re-running it is correct from
     * every state it can stop in. Both drops are `IF EXISTS`, so a second run
     * steps over whatever is already gone; the row it finishes with is still
     * there in every one of those states, because it is removed last.
     */
    public function nextStep(): string
    {
        return sprintf('bin/console tenant:deprovision %s --force', $this->slug);
    }

    /**
     * The state, as one sentence, for whoever only ever sees `getMessage()`.
     *
     * `tenant:reset` calls `deprovision()` and does not catch this, on purpose —
     * §4.1 argues that how an unexpected error is rendered is Symfony's business
     * and that swallowing it costs the stack trace `-v` exists to show. That
     * argument only holds if the message carries the facts, so it does.
     */
    private function stateSentence(): string
    {
        if (!$this->databaseDropped) {
            return sprintf(
                'Nothing was destroyed: database "%s", role "%s" and the control-plane row for "%s" '
                . 'are all still there.',
                $this->database,
                $this->role,
                $this->slug,
            );
        }

        if (!$this->roleDropped) {
            return sprintf(
                'Database "%s" is gone for good; role "%s" and the control-plane row for "%s" are '
                . 'still there. Running "%s" again clears both.',
                $this->database,
                $this->role,
                $this->slug,
                $this->nextStep(),
            );
        }

        if (!$this->filesRemoved) {
            return sprintf(
                'Database "%s" and role "%s" are gone for good; the files of "%s" and its '
                . 'control-plane row are still there. Running "%s" again clears both.',
                $this->database,
                $this->role,
                $this->slug,
                $this->nextStep(),
            );
        }

        return sprintf(
            'Database "%s", role "%s" and the files are gone for good; only the control-plane row '
            . 'for "%s" is still there. Running "%s" again clears it.',
            $this->database,
            $this->role,
            $this->slug,
            $this->nextStep(),
        );
    }

    private static function countSessions(int $sessions): string
    {
        return $sessions === 1 ? '1 session is attached' : sprintf('%d sessions are attached', $sessions);
    }
}

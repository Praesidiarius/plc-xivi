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
     * The remaining case after the terminate is a race and nothing else: a client
     * that reconnected in the moment between the two statements — an application
     * container restarting, a connection pool refilling, a second operator. The
     * drop is issued `WITH (FORCE)` precisely so that window is small, but small
     * is not zero, and a reconnecting client can win it indefinitely if it is
     * reconnecting in a loop. Which is why the advice here is to stop *that*
     * rather than to run this command harder.
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

        return sprintf(
            'Database "%s" and role "%s" are gone for good; only the control-plane row for "%s" is '
            . 'still there. Running "%s" again clears it.',
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

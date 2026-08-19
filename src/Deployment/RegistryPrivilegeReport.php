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

namespace App\Deployment;

/**
 * What {@see RegistryPrivileges} found, in the shape a deploy has to make one
 * decision from (XIV-143, docs/architecture/deployment.md §4.4).
 *
 * A value object rather than a formatted string, for the reason `TrustedHosts`
 * is not a `preg_match` in a command: the command turns this into a table and an
 * exit code, and a test asserts about the finding itself instead of grepping
 * prose it also wrote.
 *
 * **Every finding is keyed by what the privilege is on** — a table name, or one
 * of the two SQL-shaped subjects `RegistryPrivileges` uses for the database and
 * the schema. The values are the privileges, so `['tenant' => ['INSERT']]` reads
 * as the sentence it is.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RegistryPrivilegeReport
{
    /**
     * @param string                      $role        the role that was asked about
     * @param bool                        $roleExists  whether the cluster has it at all
     * @param bool                        $isSuperuser a role that passes every privilege check there is,
     *                                                 in which case nothing below was computed
     * @param array<string, list<string>> $missing     subject => privileges §4.4 grants and this role lacks
     * @param array<string, list<string>> $excess      subject => privileges this role holds and §4.4 does not grant
     * @param list<string>                $absent      registry tables this build maps and this database does not have
     * @param list<string>                $readable    what was expected to be readable, from RegistryGrants
     * @param list<string>                $withheld    what was expected to be unreachable, from RegistryGrants
     */
    public function __construct(
        public string $role,
        public bool $roleExists,
        public bool $isSuperuser,
        public array $missing,
        public array $excess,
        public array $absent,
        public array $readable = [],
        public array $withheld = [],
    ) {
    }

    /**
     * A role the cluster does not have.
     *
     * A finding rather than a skip: an installation that named a role is an
     * installation running the split deployment, and a customer-facing instance
     * whose role does not exist cannot open a connection at all. That is the
     * loudest possible version of this ticket's failure, and it is usually a
     * typo in one environment file.
     */
    public static function noSuchRole(string $role): self
    {
        return new self($role, roleExists: false, isSuperuser: false, missing: [], excess: [], absent: []);
    }

    /**
     * A role that bypasses privilege checks entirely.
     *
     * Reported on its own rather than as eighty rows of excess, because that is
     * what it is: not a grant that went wrong but §4.4 not applying to this
     * instance at all. The realistic way to arrive here is a `DATABASE_URL` that
     * still holds the administrator's credentials.
     */
    public static function superuser(string $role): self
    {
        return new self($role, roleExists: true, isSuperuser: true, missing: [], excess: [], absent: []);
    }

    /**
     * Whether this deployment's privileges match what this build's mapping asks
     * for.
     *
     * The one question `bin/deploy` asks, and the reason `absent` counts as a
     * failure alongside the two grant lists: the customer-facing instance is
     * about to query a table this database does not have, which is the same
     * outage arriving through a different error code.
     */
    public function isSatisfied(): bool
    {
        return $this->roleExists
            && !$this->isSuperuser
            && $this->missing === []
            && $this->excess === []
            && $this->absent === [];
    }

    /**
     * The findings as rows, ready for a console table: subject, privileges, and
     * which of the two problems it is.
     *
     * @return list<array{string, string, string}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->missing as $subject => $privileges) {
            $rows[] = [$subject, implode(', ', $privileges), 'not granted'];
        }

        foreach ($this->excess as $subject => $privileges) {
            $rows[] = [
                $subject,
                implode(', ', $privileges),
                \in_array($subject, $this->withheld, true) ? 'granted, and this table is withheld entirely' : 'granted beyond SELECT',
            ];
        }

        foreach ($this->absent as $table) {
            $rows[] = [$table, '—', 'mapped by this build, absent from this database'];
        }

        return $rows;
    }
}

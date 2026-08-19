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

namespace App\Command;

use App\Deployment\RegistryPrivileges;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Whether the customer-facing role's privileges still match the entity list
 * (XIV-143, docs/architecture.md §4.4).
 *
 * ## The failure mode this exists for
 *
 * `deploy:registry-grants` prints the SQL that lets a customer-facing instance
 * read the registry, and the list of tables in it is derived from the mapping —
 * so it grows the moment somebody adds an entity under `App\Registry\Entity\`.
 * The grant itself does not grow: it takes effect when a database administrator
 * runs that SQL, and **nothing checked that they had.** An installation upgraded
 * without it is one release behind on privileges, and finds out as
 * `SQLSTATE[42501]: permission denied for table notice` on every user's
 * dashboard, of every tenant, at once.
 *
 * Twice in two days ([XIV-120], [XIV-123]) the answer to that was a `CHANGELOG`
 * bullet saying to re-run the command. This is the same answer `deploy:check-hosts`
 * gives the equivalent question about hostnames, and it is a better one for the
 * same reason: **a deployment finds out instead of a customer.**
 *
 * ## Where it runs
 *
 * `bin/deploy`, immediately after the control-plane migration — which is both
 * the earliest moment the question can be asked (a table added by this release
 * exists only once that migration has run) and before the serving containers are
 * replaced, which is what makes the answer free. A non-zero exit stops the
 * deploy there, and the release being held back is the correct outcome: the old
 * containers are still serving, the old code does not read the new table, and
 * nobody is dark while somebody runs one `GRANT`.
 *
 * **Not in the container entrypoint**, which is where `deploy:check-hosts` also
 * appears, and the asymmetry is deliberate. The entrypoint's copy of that check
 * is a diagnostic that ignores its own exit code, because a refused hostname
 * belongs to one customer and refusing to start would take the others down to
 * protect them. This check's finding is instance-wide — but so is the remedy,
 * and it is one somebody has to run *as a database administrator*, which no
 * container start can do. A line in `docker logs` on every restart would
 * therefore be advice nobody is in a position to act on at the moment they read
 * it, repeated for as long as the mistake stood. The deploy is where the
 * decision is made, so the deploy is the only place this runs.
 *
 * ## It says what to run, and repairs nothing
 *
 * Decided rather than left to emerge, and the argument is §4.4's: a running
 * instance that could grant privileges to itself could be made to grant itself
 * others. Re-running `deploy:registry-grants` is idempotent, so repairing would
 * be easy and would hand back precisely the capability that command declines to
 * have. What is printed instead is the command to run, with the role already
 * filled in.
 *
 * ## The exit codes
 *
 * Borrowed from `deploy:check-hosts` and `tenant:migrate` (§4.2), so that a
 * deploy script reads all three the same way:
 *
 * | code | meaning |
 * | --- | --- |
 * | 0 | the role holds exactly what §4.4 grants it — or no role is configured, and this installation runs one image |
 * | 1 | the check could not happen: the control-plane database could not be questioned |
 * | 3 | the check happened and the privileges do not match |
 *
 * Three rather than two, because `Command::INVALID` is 2 and means "you typed
 * the command wrong" everywhere else.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'deploy:check-grants',
    description: "Report where the customer-facing role's privileges differ from what this build's registry needs",
)]
final readonly class CheckRegistryGrantsCommand
{
    /** The role's privileges are not the ones this build's mapping asks for. */
    private const int PRIVILEGES_DIFFER = 3;

    public function __construct(private RegistryPrivileges $privileges)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The database role the customer-facing instance connects as')]
        string $role = '',
    ): int {
        $role = trim($role) !== '' ? trim($role) : $this->privileges->configuredRole();

        if ($role === '') {
            // Not an error, and said in full rather than passed over — the same
            // call `deploy:check-hosts` makes about an empty XIVI_TRUSTED_DOMAINS.
            // An installation that serves everything out of one image has one
            // database user, and there is no second set of privileges for this to
            // have an opinion about.
            $io->writeln(sprintf(
                '<comment>Registry grants:</comment> %s is empty, so no restricted role was checked.',
                RegistryPrivileges::VARIABLE,
            ));
            $io->writeln(
                '  That is the default and is correct for an installation served entirely by the internal '
                . "image. Set it to the role\n  the customer-facing instance connects as if you run the "
                . 'split deployment (docs/architecture.md §4.4).',
            );

            return Command::SUCCESS;
        }

        try {
            $report = $this->privileges->audit($role);
        } catch (\Throwable $e) {
            // "Cannot tell" is not an answer to resolve in favour of deploying.
            // Same call `deploy:check-hosts` makes about an unreadable registry
            // and `PlaceholderSecretGuard` about an unreadable `.env` (§4.2).
            $io->getErrorStyle()->error(sprintf(
                'The control-plane database could not be asked what "%s" may do, so it is not known '
                . 'whether the customer-facing instance can still read the registry: %s',
                $role,
                $e->getMessage(),
            ));

            return Command::FAILURE;
        }

        if ($report->isSatisfied()) {
            $io->writeln(sprintf(
                '<info>Registry grants:</info> "%s" may read all %d registry table%s and nothing else.',
                $report->role,
                \count($report->readable),
                \count($report->readable) === 1 ? '' : 's',
            ));
            $io->writeln(sprintf(
                '  No privilege beyond SELECT anywhere, and no access to the %d table%s of the '
                . 'administration surface.',
                \count($report->withheld),
                \count($report->withheld) === 1 ? '' : 's',
            ));

            return Command::SUCCESS;
        }

        $error = $io->getErrorStyle();

        if (!$report->roleExists) {
            $error->error(sprintf(
                'The role "%s" does not exist in this cluster, so the customer-facing instance cannot '
                . 'connect to the control-plane database at all.',
                $report->role,
            ));
            $error->writeln(sprintf(
                "Either %s names the wrong role, or the role was never created.\n"
                . "bin/console deploy:registry-grants %s prints the CREATE ROLE and the grants to run\n"
                . 'as a database administrator (docs/architecture.md §4.4).',
                RegistryPrivileges::VARIABLE,
                $report->role,
            ));

            return self::PRIVILEGES_DIFFER;
        }

        if ($report->isSuperuser) {
            $error->error(sprintf(
                'The role "%s" is a superuser, so it holds every privilege in this database whatever '
                . 'was granted to it.',
                $report->role,
            ));
            $error->writeln(
                "§4.4's guarantee is that a bug in a customer-facing controller cannot write the registry, "
                . "and it does not\nhold for this role: an INSERT INTO tenant arriving from the process "
                . "facing the internet would succeed. The usual\ncause is a DATABASE_URL still carrying "
                . "the administrator's credentials.\n\n"
                . 'See docs/architecture.md §4.4.',
            );

            return self::PRIVILEGES_DIFFER;
        }

        $error->error(sprintf(
            '%d of "%s"\'s privileges are not the ones this build\'s registry needs.',
            \count($report->rows()),
            $report->role,
        ));

        $error->table(['On', 'Privilege', 'Problem'], $report->rows());

        if ($report->missing !== []) {
            $error->writeln(
                'A table the role cannot SELECT is a customer-facing page that answers 500 with '
                . "SQLSTATE[42501] the first time\nanything reads it — the dashboard, for a notice "
                . 'the operator published to everybody.',
            );
        }

        if ($report->excess !== []) {
            $error->writeln(
                'A privilege beyond SELECT is quieter and worse: everything works, and the boundary '
                . "§4.4 is made of\nis simply not there any more.",
            );
        }

        // The remedy, with the role already in it. This command deliberately
        // repairs nothing — see RegistryPrivileges for why — so the last thing it
        // prints is the command that does, run by somebody who can.
        $error->writeln(sprintf(
            "\nRun this, and apply the SQL it prints as a database administrator:\n\n"
            . "  bin/console deploy:registry-grants %s\n\n"
            . 'It is generated from this build\'s own mapping, and it starts with a REVOKE, so it '
            . "corrects both directions.\nSee docs/architecture.md §4.4.",
            $report->role,
        ));

        return self::PRIVILEGES_DIFFER;
    }
}

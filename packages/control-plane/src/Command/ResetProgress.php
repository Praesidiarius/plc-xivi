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

namespace Xivi\ControlPlane\Command;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * How far a `tenant:reset` got, so a run that dies part-way can say exactly what
 * it left behind (XIV-74).
 *
 * `tenant:reset` destroys before it builds, and no amount of care changes that:
 * the slug, the hostnames, the database name and the Postgres role all belong to
 * the tenant being replaced, so the new one cannot be stood up beside the old one
 * under the names it will eventually need. [XIV-72] already refuses everything it
 * *can* refuse before the drop — an unknown module, an unsatisfiable requirement,
 * a hostname somebody else answers on — but a `DROP DATABASE` followed by a
 * failure nobody predicted stays possible, and pretending otherwise is what
 * produced the bug this class was written for: a run that destroyed a tenant, ran
 * out of memory and said nothing about either fact.
 *
 * **The decision was to report rather than to swap.** Building the replacement
 * under a temporary slug and renaming it into place is the obvious alternative
 * and it was rejected deliberately; §4.1 of the brief carries the argument. The
 * short version is that the swap does not remove the dangerous window, it moves
 * it into a step made of `ALTER DATABASE ... RENAME`, `ALTER ROLE ... RENAME`, a
 * re-encrypted DSN and a hostname hand-over — four operations that each fail in
 * ways whose wreckage is *harder* to clear by hand than "the tenant is gone, run
 * it again", not easier. For a command that exists only in development and whose
 * whole subject matter is disposable data, precision about the wreckage buys more
 * than a machine for avoiding it.
 *
 * So this is what the precision is made of. Each step of a reset tells this
 * object it happened; if the run then dies, {@see report()} turns that into the
 * three things somebody standing in front of a broken terminal actually needs:
 * what is gone for good, what exists right now, and the line to type next.
 *
 * **Deliberately not a service.** It is constructed per run with the slug and the
 * module list of that run, which is why `config/services.yaml` excludes it from
 * the `App\` resource — a container that tried to autowire a `string $slug` would
 * fail to compile, and it would be failing about the right thing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ResetProgress
{
    private bool $destroyed = false;

    private ?string $database = null;

    private bool $admin = false;

    /** @var list<string> */
    private array $installed = [];

    /** @var list<string> */
    private array $filled = [];

    /**
     * @param string       $slug    the tenant being rebuilt
     * @param list<string> $ordered every module this run intended to install, in
     *                              the order it intended to install them — which
     *                              is what lets the report distinguish a module
     *                              that failed from one that was never reached
     */
    public function __construct(
        private readonly string $slug,
        private readonly array $ordered,
    ) {
    }

    /** The tenant that was there is gone: database, role and control-plane row. */
    public function destroyedTheOldTenant(): void
    {
        $this->destroyed = true;
    }

    /** A new row, role, database and schema exist under this database name. */
    public function provisioned(string $database): void
    {
        $this->database = $database;
    }

    public function createdTheAdminUser(): void
    {
        $this->admin = true;
    }

    public function installedModule(string $key): void
    {
        $this->installed[] = $key;
    }

    public function filledModule(string $key): void
    {
        $this->filled[] = $key;
    }

    /**
     * What the operator is left holding.
     *
     * Printed *before* the exception is re-thrown, so it sits above Symfony's own
     * error block rather than replacing it. Both halves are wanted: the stack
     * trace says what broke, and this says what it broke in the middle of, and
     * neither answers the other's question.
     *
     * @param string $registry what the control plane says about this slug *now*,
     *                         read back after the failure rather than inferred
     *                         from how far the run got. The two can disagree —
     *                         provisioning persists its row before it creates the
     *                         database — and when they do, the database is right
     * @param string $restart  the command line that starts the whole thing again
     */
    public function report(SymfonyStyle $io, string $registry, string $restart): void
    {
        $io->section(sprintf('Where "%s" stands now', $this->slug));

        $io->definitionList(
            ['The tenant that was here' => $this->destroyed
                ? 'destroyed — database, role and control-plane row dropped. Not recoverable from here.'
                : 'there was none; this run created nothing that existed before it'],
            ['The control plane' => $registry],
            ['Database' => $this->database ?? 'never created'],
            ['Admin user' => $this->admin ? 'created' : 'not created'],
            ['Modules' => $this->describeModules()],
        );

        // The one instruction that is always correct, and the reason it is: a
        // reset deprovisions whatever it finds under the slug before it builds,
        // and `deprovision()` drops with IF EXISTS. So running it again is right
        // whether what is left behind is a whole tenant, a control-plane row with
        // no database, or nothing at all — there is no state this leaves that has
        // to be cleared by hand first, which is exactly what the report is for
        // saying out loud.
        $io->text([
            ' Nothing above is rolled back. Running the reset again starts from nothing and is safe in',
            ' every state listed above:',
            '',
            sprintf('   <info>%s</info>', $restart),
            '',
            sprintf(' Or leave it: <info>bin/console tenant:deprovision %s --force</info> removes what is left.', $this->slug),
        ]);
    }

    /**
     * Every module the run meant to touch, and what became of it.
     *
     * Named one by one rather than counted, because "2 of 4 installed" leaves the
     * developer to work out *which* two from the order — and the order is the
     * thing they are least likely to have in their head, since the command worked
     * it out for them out of the blueprints.
     */
    private function describeModules(): string
    {
        if ($this->ordered === []) {
            return 'none were asked for';
        }

        $described = array_map(function (string $key): string {
            if (\in_array($key, $this->filled, true)) {
                return sprintf('%s installed and filled', $key);
            }

            if (\in_array($key, $this->installed, true)) {
                return sprintf('%s installed, empty', $key);
            }

            return sprintf('%s not reached', $key);
        }, $this->ordered);

        return implode('; ', $described);
    }
}

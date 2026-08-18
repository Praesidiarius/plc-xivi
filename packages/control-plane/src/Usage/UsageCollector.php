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

namespace Xivi\ControlPlane\Usage;

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Xivi\ControlPlane\Entity\TenantUsage;
use Xivi\ControlPlane\Repository\TenantUsageRepository;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * Goes and looks at one customer's database, and writes down how much is in it
 * (XIV-59).
 *
 * **This is the only thing in the system that deliberately touches a customer's
 * database on somebody else's behalf**, which is why the argument for it is
 * longer than the code.
 *
 * ## Why a collector at all, rather than a page that asks
 *
 * §4 is a database per customer, so there is no query that answers "how many
 * users does each tenant have" — there are fifty queries against fifty databases,
 * one connection each. A tenant list that fetched those inline would open fifty
 * connections to draw three columns, on a page whose entire purpose is to be
 * opened when somebody is already worried and in a hurry.
 *
 * The slowness is the smaller half. The larger half is that it would be the first
 * thing in Xivi that touches many tenants **in one request**, and §7.4's guarantee
 * — that a request resolves one tenant and the runtime keeps nothing between
 * requests — is not a rule anybody follows. It is a consequence of how requests
 * work here, and the moment one page is the exception, it stops being a
 * consequence and becomes something to be argued about case by case. So the
 * fan-out happens here, in a process nobody is waiting on, and the page reads the
 * control plane exactly as [XIV-58] left it: one request, one database.
 *
 * ## One tenant at a time, and the connection is shut before the next one opens
 *
 * `RecordCounter::countFor()` is not used, on purpose: this class needs four
 * answers out of the same database and taking them through four switches would
 * open and close it four times. So it opens the switch itself, asks everything
 * inside it, and lets `runFor` close it on the way out — which it does
 * unconditionally, including when the callback throws.
 *
 * **[XIV-95] made that four rather than three by keeping something already
 * read.** The counter walks the customer's own metadata to know which shapes to
 * count, so the list of modules a customer actually has was being read once per
 * collection and thrown away. It is now written down beside the figures, which is
 * what lets the tenant list say what a customer *has* rather than only what the
 * control plane arranged for them (§6.1) — without the page opening anything.
 *
 * **That closing is load-bearing rather than tidy.** There is exactly one tenant
 * connection object in the process, and `TenantSwitcher` closes it on every
 * switch, so a run over fifty customers holds one open connection at a time and
 * holds none between them. Without that, a nightly collection would sit attached
 * to every customer's database at once, and the first operator to run
 * `tenant:deprovision` during the window would watch `DROP DATABASE` refuse
 * because somebody is connected ([XIV-94]). The collection would have become the
 * thing that blocks the operator, which is the opposite of a tool for operators.
 *
 * [XIV-94] has since made a removal terminate whatever is attached, so that is no
 * longer how the leak would present — the drop would succeed and this collection
 * would be the thing killed, half way through counting somebody. The reason to
 * close the connection is unchanged and the new failure is the worse of the two to
 * diagnose, since it is silent from the operator's side.
 *
 * ## A failure is written down, not thrown
 *
 * One unreachable database must not cost the other forty-nine their figures, so
 * everything the tenant's database can raise is caught and recorded *as a
 * failure on that tenant*. Two things follow, and both matter:
 *
 *   * the row says the collection failed and when it was tried, so the page can
 *     show that rather than showing zeroes — *nothing in there* and *we could not
 *     look* are different answers, the same distinction [XIV-39] drew for a mail
 *     that was not sent;
 *   * the control-plane write still happens, because the entity manager it uses
 *     is the control plane's and the exception closed the *tenant* one. The
 *     switcher resets that manager on the way out, so the next customer starts
 *     with a working one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class UsageCollector
{
    public function __construct(
        private TenantSwitcher $switcher,
        private RecordCounter $records,
        private MetadataRepository $metadata,
        private UserRepository $users,
        private TenantUsageRepository $usages,
        private EntityManagerInterface $controlPlane,
    ) {
    }

    /**
     * Collect one customer's figures and store them, whatever happens.
     *
     * Returns rather than throws for anything the customer's database does; a
     * control-plane failure is still an exception, because a collector that
     * cannot write down what it found has nothing to report and the run should
     * stop rather than loop quietly doing nothing.
     */
    public function collect(Tenant $tenant): CollectionOutcome
    {
        // The last collection for this tenant, updated in place. One row per
        // tenant rather than a history: what an operator wants is *the* figures
        // and how old they are, and a table that grew by one row per tenant per
        // night would be a time series nobody asked for, needing a retention
        // policy nobody wrote. When somebody wants the trend, that is a ticket
        // with its own decisions in it.
        $usage = $this->usages->findOneForTenant($tenant) ?? new TenantUsage($tenant);

        try {
            /** @var array{users: int, lastLoginAt: ?\DateTimeImmutable, modules: list<string>, records: array<string, int>} $figures */
            $figures = $this->switcher->runFor($tenant, function (): array {
                // One switch, four answers, in the order that costs least to
                // explain: the users first because the table is always there, the
                // modules and their records after because how many tables that is
                // depends on what this customer installed (§6.1).
                $people = $this->users->countAndLastSignIn();

                return [
                    'users' => $people['users'],
                    'lastLoginAt' => $people['lastLoginAt'],
                    'modules' => $this->installedModules(),
                    'records' => $this->records->countInCurrentTenant(),
                ];
            });

            $usage->record(
                $figures['users'],
                $figures['lastLoginAt'],
                $figures['modules'],
                $figures['records'],
            );
            $outcome = new CollectionOutcome($usage);
        } catch (\Throwable $e) {
            // `\Throwable` rather than a driver exception list, deliberately. What
            // can come out of a tenant's database is not only a connection
            // failure: a database that exists but never got its migrations raises
            // a missing-table error, and a row of definitions the engine cannot
            // read raises something else again. Every one of them is the same fact
            // for this run — this customer could not be counted — and a narrower
            // catch would turn the interesting failures into a crashed run that
            // collected nobody.
            $usage->recordFailure($e::class);
            $outcome = new CollectionOutcome($usage, $e->getMessage());
        }

        $this->controlPlane->persist($usage);
        $this->controlPlane->flush();

        return $outcome;
    }

    /**
     * The modules the current customer's own database says it has (XIV-95).
     *
     * **Read from the metadata rather than taken from the counts beside it**, and
     * that is the decision in this method rather than the two lines of code. The
     * keys of `countInCurrentTenant()` happen to be exactly this list today,
     * because the counter walks the same `all()` — so `array_keys()` would work,
     * cost nothing, and be wrong in the way that matters. It would make *what a
     * customer has installed* a by-product of how counting is implemented: the
     * first time the counter learns to skip a shape — a module with no records
     * table, a shape the engine cannot read, a count the engine decides not to
     * make — that module would silently vanish from the installed list, and the
     * page would report a disagreement with the registry that does not exist.
     * Drift invented by our own implementation is the one failure the modules cell
     * cannot afford, because a real one is meant to send an operator looking.
     *
     * It costs nothing to ask separately. `MetadataRepository::all()` is served
     * from {@see \Xivi\Core\Metadata\MetadataCache} for as long as the tenant does
     * not move (XIV-53), and this call and the counter's are inside the same
     * switch — so the second one is an array lookup rather than a query.
     *
     * **Names, and nothing under them.** A `ModuleDefinition` in hand is a whole
     * shape — its fields, its collections, their fields, all fetch-joined — and
     * exactly one string of it leaves this method. §8.11 draws the line at *how
     * much* rather than *what*, and reading a module's definitions to learn its
     * name is on the permitted side of it; a field label would not be, and a
     * record certainly is not.
     *
     * @return list<string>
     */
    private function installedModules(): array
    {
        return array_map(
            static fn (ModuleDefinition $module): string => $module->getKey(),
            $this->metadata->all(),
        );
    }
}

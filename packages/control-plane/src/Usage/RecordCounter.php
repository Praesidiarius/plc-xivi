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
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Record\RecordRepository;

/**
 * How many live records one customer has, per module (XIV-59).
 *
 * **Extracted rather than written twice.** `tenant:deprovision` has asked this
 * question since XIV-72 — it is what the confirmation prints before it destroys a
 * database — and the usage collector asks exactly the same one. Two copies of
 * "switch to the tenant, read its own metadata, count each shape" would have
 * drifted at the first change to any of the three: a module the engine learns to
 * skip, a soft-delete predicate that moves, a second kind of shape. There is one
 * copy, and both callers get whatever it learns.
 *
 * ## Counts, and never contents
 *
 * Everything here is `COUNT(*)`. That is not a description of the current
 * implementation, it is the boundary the class exists to hold: an operator tool
 * may know *how much* a customer has and must not know *what* — see §8.11, which
 * argues why that line is where it is and what it would mean to cross it. The
 * code that opens a tenant connection to count rows is one `SELECT *` away from
 * reading them, so the method that opens the connection is deliberately the one
 * that can only return integers.
 *
 * ## The tenant's own metadata, not the module catalogue
 *
 * `MetadataRepository::all()` reads what that customer actually has installed
 * (§6.1), which is the only correct source: the blueprint in code says what a
 * fresh install would create, and a customer who never installed `invoice` has no
 * `invoice` table to count. Asking the catalogue instead would produce a query
 * against a table that does not exist, which is a failed collection reported as a
 * tenant problem.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordCounter
{
    public function __construct(
        private TenantSwitcher $switcher,
        private MetadataRepository $metadata,
        private RecordRepository $records,
    ) {
    }

    /**
     * Counts for one tenant, switching into it and back out again.
     *
     * **The connection this opens is closed again on the way out**, by
     * `runFor`'s `clear()`. That matters twice over. For `tenant:deprovision` it
     * is what keeps this from being the connection that blocks the
     * `DROP DATABASE` two steps later — Postgres refuses to drop a database
     * somebody is attached to. For a collection run walking every customer it is
     * the difference between one open connection at a time and fifty, and between
     * a run that is invisible and a run that is the reason an operator's
     * deprovision fails at three in the morning ([XIV-94]).
     *
     * Allowed to throw, and both callers are allowed to carry on: a tenant whose
     * provisioning died before the database existed is precisely the row somebody
     * wants to deprovision, and one unreachable customer must not cost the other
     * forty-nine their figures.
     *
     * @return array<string, int> module key => live records
     */
    public function countFor(Tenant $tenant): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->switcher->runFor($tenant, $this->countInCurrentTenant(...));

        return $counts;
    }

    /**
     * The same counts, for the tenant that is already current.
     *
     * Exists so that a caller with more than one question — the collector also
     * wants the user count and the last sign-in — can ask all of them inside
     * **one** switch, rather than opening and closing the customer's database once
     * per figure.
     *
     * **Calling it outside a tenant context is not a quiet mistake.** The tenant
     * connection has no usable parameters until a tenant is resolved, so this
     * throws `NoTenantResolvedException` rather than reaching the control plane or
     * whoever was current last (§7.4, §8.9) — which is what makes it safe to
     * expose a method whose contract is a precondition.
     *
     * @return array<string, int> module key => live records
     */
    public function countInCurrentTenant(): array
    {
        $counts = [];

        foreach ($this->metadata->all() as $module) {
            $counts[$module->getKey()] = $this->records->countAll($module);
        }

        return $counts;
    }
}

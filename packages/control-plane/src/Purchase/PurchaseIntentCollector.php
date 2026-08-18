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

namespace Xivi\ControlPlane\Purchase;

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\ModulePurchaseIntent;
use App\Tenant\Repository\ModulePurchaseIntentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Xivi\ControlPlane\Entity\PurchaseIntent;
use Xivi\ControlPlane\Repository\PurchaseIntentRepository;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * Goes and looks at one customer's database, and writes down what they have
 * asked to buy (XIV-102).
 *
 * ## Why a collector at all, when the operator could just be sent the request
 *
 * Because a customer's request cannot reach the control-plane database, and that
 * is [XIV-96]'s guarantee rather than an accident of wiring. §4.4 grants the
 * customer-facing instance's role `SELECT` on the registry tables and **nothing
 * else** — no `INSERT` anywhere, on any table, present or future — so a purchase
 * request written by a customer's own request has exactly one database available
 * to it: theirs. Widening the grant so that the store could write a row over here
 * would trade a boundary PostgreSQL enforces for a cron entry, which is the wrong
 * way round.
 *
 * Three other shapes were weighed and are recorded in §8.15. The one worth
 * repeating here is the tempting one: **have the store POST to a control-plane
 * endpoint**, the way [XIV-65]'s landing page posts to [XIV-64]'s signup intake.
 * It is genuinely the same pattern and it is wrong here, because it would hand
 * the customer-facing image a credential that lets it write the control plane
 * over HTTP — re-obtaining, through a network call, precisely the privilege the
 * database refuses it. §4.4's whole argument is that the sharp boundary is the
 * grant rather than the topology; a shared secret in the public image is a
 * boundary made of care again.
 *
 * ## So this is [XIV-59]'s collector, and deliberately the same one
 *
 * `UsageCollector` next door answers the same question about usage figures and
 * every sentence of its argument transfers: the fan-out over fifty databases
 * happens in a process nobody is waiting on rather than on a page somebody opened
 * because they were already worried; the page then reads the control plane and
 * stays one request against one database; and §7.4's guarantee that a request
 * resolves *one* tenant stays a consequence of how requests work rather than a
 * rule with an exception in it.
 *
 * **What it costs, said plainly:** an operator learns about a purchase request
 * within one collection interval rather than the instant it is made. That is the
 * honest price of the boundary and it is a small one — the next step is a human
 * being deciding about money and then installing a module by hand, which is not a
 * thing that was ever going to happen in the same second. The page draws the
 * collection time beside every row so that nobody has to guess how fresh the list
 * is, which is §8.11's rule about a stale figure presented as current.
 *
 * ## Three cases in one pass, and the third is the one that is easy to forget
 *
 * A request seen before is updated in place; one not seen before is inserted; and
 * **a row here whose request has gone from the customer's database is deleted**.
 * Without that third case this table would only ever grow, and the rows it
 * accumulated would be requests that no longer exist anywhere — a queue somebody
 * works through and finds half of it already fictional is a queue they stop
 * trusting. Nothing withdraws a request today, so the case exists for a customer
 * whose database was rebuilt and for whatever a future ticket adds.
 *
 * ## `installed` is observed rather than reported
 *
 * The collector is already inside the customer's database and their own metadata
 * is the truth about what they have (§6.1), so whether a request has been
 * fulfilled is *read* rather than tracked. {@see PurchaseIntent} has the argument
 * — the short version is [XIV-98]'s: a status column here would be a second copy
 * of a fact the customer's database already holds, free to disagree with it, on
 * the one screen an operator opens to find out whether they still owe somebody
 * something.
 *
 * ## Who asked does not cross
 *
 * The tenant-side row carries the person's id and the name they had at the time,
 * and neither leaves that database. §8.11 drew the line at *how much* rather than
 * *what* for the usage figures, and the same line puts a customer's own people on
 * the far side of it. An operator therefore knows which company wants which module
 * and reaches them the way they already reach them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PurchaseIntentCollector
{
    public function __construct(
        private TenantSwitcher $switcher,
        private ModulePurchaseIntentRepository $requests,
        private MetadataRepository $metadata,
        private PurchaseIntentRepository $collected,
        private EntityManagerInterface $controlPlane,
    ) {
    }

    /**
     * Collect one customer's purchase requests, whatever happens.
     *
     * Returns rather than throws for anything the customer's database does, which
     * is `UsageCollector`'s choice for its reason: one unreachable database must
     * not cost the other forty-nine their place in the queue. **Unlike the usage
     * collector, a failure here writes nothing at all** — and that asymmetry is
     * deliberate rather than an oversight.
     *
     * There, a failed collection has something true to record: *this customer
     * could not be counted, at this moment*, and the page shows that instead of
     * showing zeroes. Here the equivalent would be deleting or blanking a
     * customer's outstanding requests because their database was briefly
     * unreachable — turning a network hiccup into a queue that lost somebody's
     * order. So a failure leaves the previous collection standing, the run reports
     * it, and the timestamp on those rows tells anybody looking how old they are.
     */
    public function collect(Tenant $tenant): CollectionReport
    {
        try {
            /** @var array{requests: array<string, array{amount: string, currency: ?string, requestedAt: \DateTimeImmutable, firstRequestedAt: \DateTimeImmutable}>, installed: array<string, true>} $found */
            $found = $this->switcher->runFor($tenant, function (): array {
                // One switch, two answers. The requests are what the customer
                // asked for; the installed set is what they have, and the second
                // is what makes `installed` observed rather than tracked.
                return [
                    'requests' => array_map(
                        static fn (ModulePurchaseIntent $intent): array => [
                            'amount' => $intent->getPriceAmount(),
                            'currency' => $intent->getPriceCurrency(),
                            'requestedAt' => $intent->getRequestedAt(),
                            'firstRequestedAt' => $intent->getCreatedAt(),
                        ],
                        $this->requests->allByModule(),
                    ),
                    'installed' => $this->installedModules(),
                ];
            });
        } catch (\Throwable $e) {
            // `\Throwable` rather than a list of driver exceptions, for
            // `UsageCollector`'s reason: an unreachable database, a database with
            // no schema and a definition the engine cannot read are three
            // different exceptions and one fact for this run.
            return CollectionReport::couldNotRead($e);
        }

        $existing = $this->collected->forTenantByModule($tenant);
        $written = 0;

        foreach ($found['requests'] as $key => $request) {
            $intent = $existing[$key] ?? null;

            if ($intent === null) {
                $intent = new PurchaseIntent($tenant, $key);
                $this->controlPlane->persist($intent);
            }

            $intent->record(
                $request['amount'],
                $request['currency'],
                $request['requestedAt'],
                $request['firstRequestedAt'],
                isset($found['installed'][$key]),
            );

            unset($existing[$key]);
            ++$written;
        }

        // Whatever is left in $existing is a row here with no request behind it
        // any more — see the class docblock for why it goes rather than staying
        // as history.
        foreach ($existing as $stale) {
            $this->controlPlane->remove($stale);
        }

        $this->controlPlane->flush();

        return CollectionReport::collected($written, \count($existing));
    }

    /**
     * Which modules the current customer's own database says it has, as a set.
     *
     * Read from the metadata because §6.1 makes it the truth: the registry's
     * `enabled_modules` is what the control plane *arranged*, and [XIV-95] exists
     * because the two can differ. A request marked fulfilled on the strength of
     * the registry's belief would be an operator told they had served somebody
     * they had not.
     *
     * **Names, and nothing under them.** A `ModuleDefinition` in hand is a whole
     * shape; exactly one string of each leaves this method, which is the same line
     * `UsageCollector::installedModules()` holds and for the same reason.
     *
     * @return array<string, true>
     */
    private function installedModules(): array
    {
        $installed = [];

        foreach ($this->metadata->all() as $module) {
            \assert($module instanceof ModuleDefinition);

            $installed[$module->getKey()] = true;
        }

        return $installed;
    }
}

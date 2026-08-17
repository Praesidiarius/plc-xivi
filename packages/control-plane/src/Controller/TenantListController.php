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

namespace Xivi\ControlPlane\Controller;

use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Xivi\ControlPlane\Repository\TenantUsageRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\View\TenantSummary;

/**
 * Every customer on this installation, on one page (XIV-58).
 *
 * **This replaces XIV-57's placeholder rather than sitting beside it.** That page
 * existed to have somewhere to land while the security boundary underneath it was
 * being built, and its own docblock said this ticket would remove it. It is gone,
 * and so is `@XiviControlPlane/index.html.twig`; `control_plane_home` is
 * still the route name, because `security.yaml` sends a freshly signed-in
 * operator to it and that is the same promise either way.
 *
 * There is nothing here but a `SELECT`, and that is the ticket. `tenant:list`
 * already answers the same question at a console and **keeps working** — a
 * headless deployment needs it, and a page is not a reason to take a command
 * away. What a page adds is not the data; it is that somebody who is not at a
 * terminal can look, and that the layout can decide what they see first.
 *
 * ## One request, one database — and here that database is the control plane's
 *
 * **This page opens no tenant connection at all.** Not "avoids one where it can",
 * not "does not need one yet": none, and that is the property the page is built
 * around rather than an incidental fact about the current columns.
 *
 * Every column drawn here — the name, the slug, the status, the plan, the
 * hostnames, the two dates, the enabled modules — is a field of the registry row,
 * and the usage cell beside them is a row of `tenant_usage`, which is a
 * control-plane table too (XIV-59). The control-plane database answers all of it
 * in two queries, which is why {@see TenantSummary} can be a pure mapping over
 * rows of that one database and why nothing on the path from here to the
 * template has a `TenantSwitcher` in it. A
 * control-plane request resolves no tenant at all (§8.9), so the `tenant`
 * connection is deliberately left unusable: anything reaching for it does not
 * quietly get the previous customer's database, it throws
 * `NoTenantResolvedException` and the page 500s. `TenantListTest` proves both
 * halves — that a request for this page leaves the tenant connection unopened,
 * and that the connection really would have failed loudly if it had been touched,
 * so the first assertion is not vacuous.
 *
 * **The usage figures are the case this was written for, and they did not change
 * it** (XIV-59). How many users a customer has, when anybody last signed in and
 * how many records are in there are exactly the columns that would have been one
 * join away if the join existed. They live in each customer's own database, one
 * connection per customer, and a page listing forty tenants that fetched them
 * inline would open forty connections to draw one cell — on a page whose entire
 * purpose is to be opened by somebody who is already worried.
 *
 * So they are not fetched here at all. `tenant:usage:collect` walks the tenants
 * **one at a time**, out of any request, and writes what it finds into
 * `tenant_usage`; this page reads that table and says how old the figures are.
 * The consequence for anybody editing this file is the same as it was before: the
 * moment this page opens one tenant connection, that arrangement is pointless,
 * because the expensive thing will have become the easy thing again. The next
 * figure somebody wants here goes into the collector, not into this method.
 *
 * ## The status column is the reason the page is ordered the way it is
 *
 * A registry sorted by name is a registry in which a tenant that has been stuck
 * in `provisioning` since Tuesday is on the third screen, between two healthy
 * customers, in a cell that looks like every other cell. Provisioning takes
 * seconds; a tenant found in that state by somebody loading a page is not
 * mid-flight, it is what a run that died halfway through left behind (§4.1). So
 * the ordering is by {@see \App\Registry\Entity\TenantStatus::attentionRank()}
 * first and by name second, and the page opens with a line saying how many
 * customers are not being served and naming them.
 *
 * **Rejected: computing "stuck" from a threshold** — `provisioning` and
 * `updated_at` older than a day, drawn as a warning. It was the obvious reading
 * of the ticket and it is not built, because the threshold would be fiction. A
 * tenant that has been provisioning for twenty-three hours is exactly as broken
 * as one that has been provisioning for twenty-five, and a rule that draws a line
 * between them teaches the reader that everything under the line is fine. The
 * honest statement is the weaker one this page makes instead: *this customer is
 * not being served, and here is when the row was created and when it was
 * provisioned* — which for a stuck tenant is a date and a dash, and reads as what
 * it is. The reader supplies the judgement, which is the half of "has it moved in
 * a day" that a constant cannot.
 *
 * **Rejected: a separate page or a filter for the unhealthy rows.** Both put the
 * thing worth seeing behind a click, on a page whose whole job is that nobody has
 * to go looking. The cost of the ordering chosen instead is real and small: an
 * operator looking up one customer by name now finds them in the second group
 * rather than in strict alphabetical position. The registry has one row per
 * customer, so this is a list of tens, and grouping a list of tens by state is a
 * reading order rather than an obstacle. When it stops being tens, the answer is
 * a search box and paging, not a different sort.
 *
 * ## Sorting in PHP
 *
 * `attentionRank()` is a PHP `match`, so the ordering above could only reach SQL
 * as a `CASE WHEN status = 'provisioning' THEN 0 …` written into a DQL
 * `ORDER BY` — a second copy of the ranking, in a different language, that nothing
 * would notice diverging from the enum. Sorting the fetched rows instead keeps
 * one definition of the order and costs a `usort` over a list bounded by the
 * number of customers this installation has. If that list ever grows to where the
 * sort matters, it has long since grown to where the *page* matters, and the fix
 * then is paging — which needs the ordering in SQL and is the moment to pay for
 * the duplication, with a reason.
 *
 * **No `#[IsGranted]`, and nothing to grant.** `access_control` requires
 * `ROLE_OPERATOR` for everything under {@see ControlPlaneHost::PATH_PREFIX},
 * `ControlPlaneRequestListener` makes these paths not exist on a customer's
 * hostname at all, and the control-plane firewall is what answers a credential
 * here. An operator has that role and only that role, and inventing a permission
 * model before there is a second kind of operator would be modelling a guess
 * (see `Operator`, §8.9).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantListController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantUsageRepository $usages,
    ) {
    }

    #[Route(ControlPlaneHost::PATH_PREFIX . '/', name: 'control_plane_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        // Every collection there is, in one query, keyed by tenant — fetched
        // before the loop rather than inside it, so that a page listing forty
        // customers is two queries and not forty-one (XIV-59). Both of them go to
        // the default entity manager, which is the control plane's (see
        // config/packages/doctrine.yaml); neither goes anywhere near a customer's
        // database, which is the whole arrangement.
        $usages = $this->usages->bySlug();

        // The reads this page makes, mapped straight into summaries so that no
        // `Tenant` — and therefore no DSN and no encrypted password — is ever in
        // the variables the template can see.
        $tenants = array_map(
            fn (Tenant $tenant): TenantSummary => TenantSummary::of($tenant, $usages[$tenant->getSlug()] ?? null),
            $this->tenants->findAllWithDomains(),
        );

        // Stable within a rank: the repository has already ordered by name and
        // `usort` in PHP 8 is stable, so equal ranks keep that order rather than
        // shuffling between requests. A list that reorders itself on reload is a
        // list nobody trusts.
        usort(
            $tenants,
            static fn (TenantSummary $a, TenantSummary $b): int => $a->status->attentionRank() <=> $b->status->attentionRank(),
        );

        return $this->render('@XiviControlPlane/tenants.html.twig', [
            'tenants' => $tenants,

            // Passed separately rather than filtered in the template, because it
            // is the page's headline rather than a presentation detail: the first
            // sentence an operator reads is how many customers are not being
            // served, and it is drawn only when that number is not zero. A banner
            // that is always there saying "0 problems" is furniture, and furniture
            // is what somebody stops reading.
            'notServing' => array_values(array_filter(
                $tenants,
                static fn (TenantSummary $tenant): bool => !$tenant->servesRequests(),
            )),
        ]);
    }
}

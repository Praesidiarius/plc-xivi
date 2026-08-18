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

namespace Xivi\ControlPlane\View;

use Xivi\ControlPlane\Entity\TenantUsage;

/**
 * One customer's figures as the tenant list draws them (XIV-59).
 *
 * The same shape and the same reason as {@see TenantSummary}: a readonly object
 * of scalars, built by one static factory, so that what a template can see is
 * decided here rather than by whoever edits the template next.
 *
 * **What it deliberately does not carry is the failure's class name.** The row
 * stores that — it is the difference between a database that was unreachable and
 * a schema that was missing, and it is worth having — but the page has no use for
 * it beyond looking technical, and every value that reaches HTML is a value
 * somebody can later be tempted to make more helpful. The page says *we could not
 * read this customer, at this time*, which is the whole of what an operator can
 * act on from a list; the run's own output has the driver's words in full.
 *
 * There is no object of this type for a customer nobody has collected yet. That
 * state is the null on {@see TenantSummary::$usage}, not a flag in here — see
 * {@see TenantUsage} for why absence is the honest representation of "never
 * looked".
 *
 * **[XIV-95] added the installed module list to this object rather than beside
 * it**, and that placement is the argument. What a customer has installed is a
 * fact about their database read at a moment, exactly like the record count next
 * to it: put it on {@see TenantSummary} and the template gains a list with no
 * timestamp attached, which is the shape of every stale figure this design spent
 * a ticket avoiding. Here it is unreachable without also having `collectedAt`
 * and `failed` in hand, so a template cannot draw the list without being able to
 * say how old it is.
 *
 * **The breakdown by module used to be a string for a `title` attribute**, built
 * here by a `recordBreakdown()` method that is gone. A tooltip is invisible on a
 * touch screen and to a screen reader, and it was the only place the per-module
 * counts appeared — so the answer to "of what" was available to a mouse and to
 * nobody else. The counts are drawn as text now, one module per line, next to the
 * names they belong to; see the modules cell in `tenants.html.twig` and
 * {@see ModuleReconciliation}.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantUsageSummary
{
    /**
     * @param list<string>       $installedModules
     * @param array<string, int> $recordsByModule
     */
    private function __construct(
        public \DateTimeImmutable $collectedAt,
        public bool $failed,
        public ?int $userCount,
        public ?\DateTimeImmutable $lastLoginAt,
        public ?int $recordCount,
        public array $installedModules,
        public array $recordsByModule,
    ) {
    }

    public static function of(TenantUsage $usage): self
    {
        return new self(
            $usage->getCollectedAt(),
            $usage->hasFailed(),
            $usage->getUserCount(),
            $usage->getLastLoginAt(),
            $usage->getRecordCount(),
            $usage->getInstalledModules(),
            $usage->getRecordsByModule(),
        );
    }
}

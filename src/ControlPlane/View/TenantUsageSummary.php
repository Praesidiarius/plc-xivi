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

namespace App\ControlPlane\View;

use App\ControlPlane\Entity\TenantUsage;

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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantUsageSummary
{
    /** @param array<string, int> $recordsByModule */
    private function __construct(
        public \DateTimeImmutable $collectedAt,
        public bool $failed,
        public ?int $userCount,
        public ?\DateTimeImmutable $lastLoginAt,
        public ?int $recordCount,
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
            $usage->getRecordsByModule(),
        );
    }

    /**
     * The breakdown as one line of text, for the cell's tooltip.
     *
     * Module keys and integers, which is all the row holds — the count says how
     * much and never what, and that boundary is argued in §8.11 rather than left
     * to be inferred from the fact that nothing here happens to select a record.
     */
    public function recordBreakdown(): string
    {
        $parts = [];

        foreach ($this->recordsByModule as $module => $count) {
            $parts[] = sprintf('%s %d', $module, $count);
        }

        return implode(', ', $parts);
    }
}

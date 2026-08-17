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

namespace App\ControlPlane\Usage;

use App\ControlPlane\Entity\TenantUsage;

/**
 * What collecting one customer's figures produced (XIV-59).
 *
 * Two things, and they are addressed to two different readers. The `usage` row is
 * what the control plane keeps and what the tenant list draws; `reason` is a
 * sentence for the terminal of whoever ran the collection, and is deliberately
 * **not** stored — see {@see TenantUsage} for why a driver's own words have no
 * business in a table whose rows end up on an HTML page.
 *
 * The collector returns this rather than throwing, because a failed tenant is an
 * ordinary outcome of a run over every tenant rather than an exception to it: the
 * caller's job is to write it down, say so, and move to the next customer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CollectionOutcome
{
    public function __construct(
        public TenantUsage $usage,
        /** The driver's message, or null when the collection succeeded. */
        public ?string $reason = null,
    ) {
    }

    public function failed(): bool
    {
        return $this->reason !== null;
    }
}

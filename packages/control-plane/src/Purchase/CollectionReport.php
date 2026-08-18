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

/**
 * What collecting one customer's purchase requests produced (XIV-102).
 *
 * {@see \Xivi\ControlPlane\Usage\CollectionOutcome}'s sibling and deliberately
 * not the same class, because the two report different things: a usage collection
 * always produces a row and this one may legitimately produce none at all — a
 * customer who has asked for nothing is the ordinary case rather than an empty
 * result to be explained.
 *
 * The numbers are for the terminal rather than for a screen. Nothing here is
 * stored, and the failure carries the driver's own words for exactly the reason
 * {@see \Xivi\ControlPlane\Entity\TenantUsage} refuses to store them: a
 * connection error names the host, the port and the role, which is fine in the
 * terminal of somebody who already has the DSN and has no business anywhere a
 * page might render it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CollectionReport
{
    private function __construct(
        /** How many of this customer's requests were written down. */
        public int $collected,
        /** How many rows here no longer had a request behind them and went. */
        public int $removed,
        /** The exception's message, or null when the customer's database answered. */
        public ?string $reason = null,
    ) {
    }

    public static function collected(int $collected, int $removed): self
    {
        return new self($collected, $removed);
    }

    /**
     * The customer's database did not answer, and **nothing was written**.
     *
     * The zeroes are literal rather than unknown: {@see PurchaseIntentCollector}
     * leaves the previous collection standing on a failure, deliberately, so that
     * a network hiccup cannot empty somebody's place in the queue.
     */
    public static function couldNotRead(\Throwable $e): self
    {
        return new self(0, 0, $e->getMessage());
    }

    public function failed(): bool
    {
        return $this->reason !== null;
    }
}

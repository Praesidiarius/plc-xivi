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

namespace Xivi\ControlPlane\Support;

/**
 * What one customer's support collection did (XIV-123).
 *
 * **Not `Xivi\ControlPlane\Purchase\CollectionReport`, and the difference is one
 * field that would be a lie.** That type carries a `removed` count, because a
 * purchase request that has gone from the customer's database is deleted here —
 * a queue half full of requests that no longer exist is a queue somebody stops
 * trusting. {@see SupportTicketCollector} deliberately removes nothing, so a
 * shared type would advertise a behaviour this collector refuses, and every
 * caller would have to know that one of the two numbers is always zero.
 *
 * The duplication is three fields, and this codebase's usual objection to
 * copying — that two copies drift — points the other way here: they are supposed
 * to differ.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CollectionReport
{
    private function __construct(
        /** How many of this customer's tickets are now in front of an operator. */
        public int $collected,
        /** How many of those were seen for the first time by this run. */
        public int $new,
        /** The exception's message, or null when the customer's database answered. */
        public ?string $reason = null,
    ) {
    }

    public static function collected(int $collected, int $new): self
    {
        return new self($collected, $new);
    }

    /**
     * The customer's database did not answer, or answered with something the
     * engine could not read.
     *
     * One fact for the run rather than a taxonomy of driver exceptions —
     * `UsageCollector`'s treatment, and the reason is that an unreachable
     * database, a database with no schema and a broken definition are three
     * exceptions and one thing an operator does about them.
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

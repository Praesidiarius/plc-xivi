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

namespace Xivi\Core\Schedule;

/**
 * **One thing that should have happened by now**, and the whole of what makes it
 * distinguishable from the next one (XIV-155, §6.7).
 *
 * Two values, and the reason there are exactly two is that they plus
 * {@see RecurringWork::key()} are the identity the engine records and refuses to
 * record twice. Anything else a module wants while running (the amount, the
 * customer, the plan) it re-reads from its own records, which is both cheaper
 * than carrying it here and more correct: the run happens after the answer was
 * computed, and the record is the truth at the moment of the run rather than at
 * the moment of the question.
 *
 * **`subject` is which record this is about**, as a string because the engine
 * does not care what it is. A definition's id is the ordinary answer;
 * `RecurringWork` implementations that recur once per tenant rather than once per
 * record pass a constant. It is not a foreign key and cannot be: the row here
 * outlives the record it names, deliberately, for the same reason
 * {@see \Xivi\Core\Numbering\NumberAllocator}'s counters do. Deleting the
 * recurring definition must not make last month's invoice generate again.
 *
 * **`period` is which occurrence**, as an absolute instant. Not a label, not a
 * `2026-08` string: a moment, stored `timestamptz` like everything else in this
 * application (§8.4.4). The module builds it in the tenant's zone, the first of
 * August at midnight in Zurich, and what lands in the column is that instant. A
 * label would have been friendlier to read and would have needed a rule for
 * every cadence anybody ever adds; an instant needs none, sorts, and compares.
 *
 * **What follows from that, and it is worth knowing before it surprises
 * somebody**: a customer who changes their timezone setting changes where future
 * period boundaries fall, so the same nominal month may be a different instant
 * afterwards. Occurrences already recorded keep the instant they were recorded
 * with and are never re-run; the next boundary is computed in the new zone. That
 * is the honest behaviour rather than a defect, because the alternative is a
 * stored label that quietly means two different moments, and it is why §6.7 says
 * the setting is one to answer at onboarding rather than in March.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Occurrence
{
    public function __construct(
        public string $subject,
        public \DateTimeImmutable $period,
    ) {
    }

    /**
     * How this occurrence reads in a terminal: `4 @ 2026-08-01T00:00:00+02:00`.
     *
     * The offset is kept rather than normalised, because the whole of what an
     * operator is checking when they read one of these is whether the boundary
     * landed on the customer's clock or on the server's.
     */
    public function describe(): string
    {
        return sprintf('%s @ %s', $this->subject, $this->period->format(\DateTimeInterface::ATOM));
    }
}

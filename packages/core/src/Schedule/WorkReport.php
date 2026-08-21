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
 * What one tenant's turn of the clock came to (XIV-155, §6.7).
 *
 * Three numbers and a list, and the split between the first two is the one worth
 * reading. **Ran** is work that happened and produced something. **Passed** is
 * work a {@see CatchUp::OnlyTheLatest} declaration wrote off after an outage,
 * which is not an error and not an achievement; it is on the report because an
 * operator reading the morning after a two-day outage should be told that four
 * occurrences were deliberately not done, rather than left to notice the absence.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class WorkReport
{
    /**
     * @param list<string>      $ran      one line per occurrence that happened
     * @param list<string>      $passed   one line per occurrence written off
     * @param list<WorkFailure> $failures
     */
    public function __construct(
        public array $ran = [],
        public array $passed = [],
        public array $failures = [],
    ) {
    }

    public function failed(): bool
    {
        return $this->failures !== [];
    }

    /**
     * Whether this tenant had anything at all to say, which is what decides
     * whether it gets a line in the run's output.
     *
     * Most tenants on most hours have nothing due, and a walk that printed
     * fifty "nothing to do" lines every hour would be a walk whose real lines
     * nobody finds.
     */
    public function isQuiet(): bool
    {
        return $this->ran === [] && $this->passed === [] && $this->failures === [];
    }
}

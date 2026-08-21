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
 * One thing that went wrong inside one tenant, said in enough words to act on
 * (XIV-155, §6.7).
 *
 * The two `$occurrence` cases are different failures and are worth telling apart
 * on sight. **Null** means the module could not even say what was outstanding,
 * because {@see RecurringWork::due()} threw. That is usually §6.1 arriving: the
 * customer renamed or removed the field the declaration reads, and the module's
 * query no longer matches their shape. Nothing was attempted and nothing is
 * lost. **A value** means one particular occurrence was attempted and rolled
 * back; it is still outstanding and the next run of the clock will offer it
 * again.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class WorkFailure
{
    private function __construct(
        public string $job,
        public ?Occurrence $occurrence,
        public string $reason,
    ) {
    }

    /** The module could not say what was outstanding. */
    public static function asking(string $job, \Throwable $cause): self
    {
        return new self($job, null, $cause->getMessage());
    }

    /** One occurrence was attempted and did not survive its transaction. */
    public static function running(string $job, Occurrence $occurrence, \Throwable $cause): self
    {
        return new self($job, $occurrence, $cause->getMessage());
    }

    /**
     * How this reads in a terminal, without the tenant, which the caller adds
     * because it is the thing every line of the report shares.
     */
    public function describe(): string
    {
        return $this->occurrence === null
            ? sprintf('%s: could not say what is due: %s', $this->job, $this->reason)
            : sprintf('%s (%s): %s', $this->job, $this->occurrence->describe(), $this->reason);
    }
}

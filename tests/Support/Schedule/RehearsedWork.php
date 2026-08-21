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

namespace App\Tests\Support\Schedule;

use App\Tests\Support\Module\JobModule;
use Xivi\Core\Numbering\NumberAllocator;
use Xivi\Core\Schedule\CatchUp;
use Xivi\Core\Schedule\Occurrence;
use Xivi\Core\Schedule\RecurringWork;

/**
 * A module's recurring work, declared in the test environment and shipped in no
 * build (XIV-155, §6.7).
 *
 * The same device {@see JobModule} is, for the same reason and against the same
 * objection. The clock ([XIV-155]) landed before either of the two consumers it
 * was built for, [XIV-156]'s recurring invoices and [XIV-157]'s memberships,
 * because §1's rule says a capability two modules need belongs to the engine
 * *before* the first copy of it is written into a module. That leaves the seam
 * with no production implementation for as long as it takes those to ship, and
 * the choice is between testing it against something invented here or giving a
 * customer-facing module a schedule nobody asked for.
 *
 * **The effect is a document number, and that is the load-bearing choice.**
 * `run()` allocates from {@see NumberAllocator}, which is a real counter in a
 * real table moved by one statement, so "this occurrence ran twice" is not a
 * counter in PHP that a test could believe on its own word, it is a customer's
 * sequence at 3 when it should be at 2. It is also transactional in exactly the
 * way the guarantee depends on: work that throws after allocating gives the
 * number back, and the test can then see that neither the number nor the record
 * of the occurrence survived.
 *
 * Everything else about this class is a knob, because the runner's behaviour is
 * mostly about what a module does *wrong*: not answering, throwing halfway,
 * belonging to a module the customer never installed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RehearsedWork implements RecurringWork
{
    public const string KEY = 'test.rehearsed';

    /** The counter `run()` draws from, so a test can read it back. */
    public const string SHAPE = 'rehearsal';
    public const string FIELD = 'work';

    /**
     * What {@see due()} answers with.
     *
     * @var list<Occurrence>
     */
    public array $offer = [];

    /**
     * What {@see run()} was actually handed, in order.
     *
     * @var list<Occurrence>
     */
    public array $ran = [];

    /**
     * The numbers those runs allocated, one per call that got as far as the
     * counter.
     *
     * @var list<int>
     */
    public array $numbers = [];

    /** Which module this belongs to, so "not installed here" can be rehearsed. */
    public string $module = JobModule::KEY;

    public CatchUp $catchUp = CatchUp::EveryMissedPeriod;

    /** A customer who renamed the field this reads: {@see due()} throws. */
    public ?\Throwable $cannotSay = null;

    /**
     * Subjects whose {@see run()} throws, after allocating, so the rollback has
     * something to undo.
     *
     * @var list<string>
     */
    public array $cannotRun = [];

    /** What the engine said the tenant's clock was, for the timezone tests. */
    public ?\DateTimeZone $askedIn = null;

    /** And the instant it asked about. */
    public ?\DateTimeImmutable $askedAt = null;

    public function __construct(private readonly NumberAllocator $numbering)
    {
    }

    /**
     * Back to a declaration that offers nothing and does nothing.
     *
     * The service is a singleton for the length of a test's kernel, so a class
     * that set `$offer` and did not clear it would hand its occurrences to the
     * next test in the same process.
     */
    public function reset(): void
    {
        $this->offer = [];
        $this->ran = [];
        $this->numbers = [];
        $this->module = JobModule::KEY;
        $this->catchUp = CatchUp::EveryMissedPeriod;
        $this->cannotSay = null;
        $this->cannotRun = [];
        $this->askedIn = null;
        $this->askedAt = null;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return $this->module;
    }

    public function catchUp(): CatchUp
    {
        return $this->catchUp;
    }

    public function due(\DateTimeImmutable $now, \DateTimeZone $zone): iterable
    {
        $this->askedAt = $now;
        $this->askedIn = $zone;

        if ($this->cannotSay !== null) {
            throw $this->cannotSay;
        }

        return $this->offer;
    }

    public function run(Occurrence $occurrence): void
    {
        // Before the refusal, deliberately: a work that fails *after* changing
        // the database is the case the transaction exists for, and a double that
        // failed first would never exercise it.
        $this->numbers[] = $this->numbering->next(self::SHAPE, self::FIELD, '');
        $this->ran[] = $occurrence;

        if (\in_array($occurrence->subject, $this->cannotRun, true)) {
            throw new \RuntimeException(sprintf('Subject "%s" refuses to run.', $occurrence->subject));
        }
    }
}

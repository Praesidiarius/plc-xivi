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

namespace App\Tests\Unit\Lifecycle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Xivi\Core\Lifecycle\GuardedRecord;
use Xivi\Core\Lifecycle\Lifecycle;
use Xivi\Core\Lifecycle\LifecycleTransition;
use Xivi\Core\Lifecycle\RecordLifecycle;
use Xivi\Core\Lifecycle\RecordMarkingStore;
use Xivi\Core\Lifecycle\TransitionGuard;
use Xivi\Core\Lifecycle\TransitionRefused;
use Xivi\Core\Record\Record;

/**
 * A move the state allows and the record refuses (XIV-110).
 *
 * A unit test, because the interesting half of the mechanism has no database in
 * it: whether the predicate is consulted, how often, in what order relative to
 * the state machine's own answer, and whether the two callers — the page
 * deciding what to draw and the route deciding what to allow — really do get
 * their answer from the same place. The end-to-end half, where a real order with
 * no lines meets a real button, is `OrderModuleTest`.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TransitionGuardTest extends TestCase
{
    /** The move is legal from here, and the record is not ready for it. */
    public function testAGuardedTransitionIsNotOffered(): void
    {
        $lifecycle = $this->jobs(new StubGuard('guard.needs_a_title'));

        self::assertSame(['cancel'], $this->namesOf($lifecycle->enabledFor(new Record(['status' => 'draft']))));
    }

    /** And when it has nothing to say, nothing changes. */
    public function testAGuardThatAllowsChangesNothing(): void
    {
        $lifecycle = $this->jobs(new StubGuard(null));

        self::assertSame(['start', 'cancel'], $this->namesOf($lifecycle->enabledFor(new Record(['status' => 'draft']))));
    }

    /**
     * The page gets the refused move *and* its reason, rather than a shorter
     * list — a button that is simply missing explains nothing, and the sentence
     * the module wrote would otherwise only ever be seen by somebody retyping a
     * URL.
     */
    public function testARefusedMoveIsOfferedWithItsReason(): void
    {
        $offers = $this->jobs(new StubGuard('guard.needs_a_title'))->offeredFor(new Record(['status' => 'draft']));

        self::assertSame(['start', 'cancel'], $this->namesOf(array_map(
            static fn (object $offer): LifecycleTransition => $offer->transition,
            $offers,
        )));
        self::assertFalse($offers[0]->isPossible());
        self::assertSame('guard.needs_a_title', $offers[0]->refusal);
        self::assertTrue($offers[1]->isPossible(), 'the unguarded move is untouched');
        self::assertNull($offers[1]->refusal);
    }

    /**
     * **The enforcement.** The button being absent is the courtesy; this is what
     * makes it true, because a POST is a URL and a URL can be retyped.
     */
    public function testApplyingAGuardedTransitionIsRefused(): void
    {
        $record = new Record(['status' => 'draft']);

        try {
            $this->jobs(new StubGuard('guard.needs_a_title'))->apply($record, 'start');
            self::fail('the guard should have refused');
        } catch (TransitionRefused $refused) {
            // The module's key is the domain, so the sentence comes out of the
            // same catalogue the button's label does.
            self::assertSame('guard.needs_a_title', $refused->translatable()->getMessage());
            self::assertSame('job', $refused->translatable()->getDomain());
        }

        self::assertSame('draft', $record->get('status'), 'and the record did not move');
    }

    /**
     * A guard narrows and never widens: it is asked only about moves the state
     * machine has already allowed, so it cannot let a record take a move from
     * somewhere the graph forbids.
     */
    public function testAGuardIsNotAskedAboutAMoveTheStateForbids(): void
    {
        $guard = new StubGuard(null);
        $lifecycle = $this->jobs($guard);

        // "start" is legal only from draft, and this record is done.
        self::assertSame([], $lifecycle->enabledFor(new Record(['status' => 'done'])));
        self::assertSame(0, $guard->asked, 'and it cost nothing to not offer it');
    }

    /**
     * The two questions are the same predicate, and the state's refusal is the
     * one that wins: a move that is not available from here is refused as such,
     * without the guard being consulted at all.
     */
    public function testTheStateIsAnsweredBeforeTheGuard(): void
    {
        $guard = new StubGuard('guard.needs_a_title');

        $this->expectException(TransitionRefused::class);
        $this->expectExceptionMessage('Cannot "start" from "done"');

        $this->jobs($guard)->apply(new Record(['status' => 'done']), 'start');
    }

    /** One ask, one evaluation per guarded transition. */
    public function testTheGuardIsAskedOncePerMoveItGuards(): void
    {
        $guard = new StubGuard(null);

        $this->jobs($guard)->offeredFor(new Record(['status' => 'draft']));

        self::assertSame(1, $guard->asked);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * A two-transition lifecycle wired the way {@see \Xivi\Core\Lifecycle\Lifecycles}
     * wires one: `start` carries the guard, `cancel` carries none and is the way
     * out that must stay open.
     */
    private function jobs(TransitionGuard $guard): RecordLifecycle
    {
        $lifecycle = new Lifecycle('status', 'draft', [
            new LifecycleTransition('start', ['draft'], 'active', guard: $guard),
            new LifecycleTransition('cancel', ['draft', 'active'], 'cancelled'),
        ]);

        $transitions = [];

        foreach ($lifecycle->transitions as $transition) {
            foreach ($transition->from as $from) {
                $transitions[] = new Transition($transition->name, $from, $transition->to);
            }
        }

        return new RecordLifecycle(
            $lifecycle,
            new StateMachine(
                new Definition([...$lifecycle->states(), 'done'], $transitions, $lifecycle->initial),
                new RecordMarkingStore($lifecycle),
                name: 'job',
            ),
            'job',
        );
    }

    /**
     * @param list<LifecycleTransition> $transitions
     *
     * @return list<string>
     */
    private function namesOf(array $transitions): array
    {
        return array_map(static fn (LifecycleTransition $t): string => $t->name, $transitions);
    }
}

/**
 * A guard with a fixed answer, counting how often it was asked.
 *
 * The count is the assertion that matters here: a predicate that may read a
 * record's rows is one somebody will eventually call in a loop, and this is
 * where that gets noticed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class StubGuard implements TransitionGuard
{
    public int $asked = 0;

    public function __construct(private readonly ?string $refusal)
    {
    }

    public function refusal(GuardedRecord $record): ?string
    {
        ++$this->asked;

        return $this->refusal;
    }
}

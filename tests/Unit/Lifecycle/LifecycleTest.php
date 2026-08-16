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
use Xivi\Core\Lifecycle\Lifecycle;
use Xivi\Core\Lifecycle\LifecycleTransition;

/**
 * The transition graph read as a graph (XIV-73).
 *
 * A unit test because there is no record, no tenant and no database in the
 * question: "how does something get from draft to delivered" is answered by the
 * declaration alone, and the answers worth checking are the awkward ones — the
 * state you cannot reach, the state you are already in, and the two roads to
 * cancelled.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class LifecycleTest extends TestCase
{
    public function testItFindsTheWayThroughAnIntermediateState(): void
    {
        self::assertSame(['confirm', 'deliver'], $this->pathTo('delivered'));
    }

    public function testTheDirectRouteWinsOverTheLongOne(): void
    {
        // Cancelling is legal from draft and from confirmed, so there are two
        // ways to a cancelled order. The short one is the one a record should
        // take, or every generated cancellation would have been confirmed first.
        self::assertSame(['cancel'], $this->pathTo('cancelled'));
    }

    public function testOneStepIsOneStep(): void
    {
        self::assertSame(['confirm'], $this->pathTo('confirmed'));
    }

    /** Already there. Nothing to do, and not an error. */
    public function testThereIsNoWayFromAStateToItself(): void
    {
        self::assertSame([], $this->pathTo('draft'));
    }

    /**
     * A choice field may hold options the lifecycle never mentions — §5.4 lets
     * somebody add one — and a state with no way into it is the same answer as
     * being there already: nothing happens.
     */
    public function testAnUnreachableStateGivesNoPath(): void
    {
        self::assertSame([], $this->pathTo('archived'));
    }

    /** Backwards is not a path just because forwards was. */
    public function testItWillNotWalkATransitionTheWrongWay(): void
    {
        self::assertSame([], $this->pathTo('draft', from: 'delivered'));
    }

    /**
     * A lifecycle that loops is not this project's shape today, but a search
     * that revisits states would hang on one rather than fail a test, so the
     * termination is asserted where it can still be read.
     */
    public function testALoopDoesNotTrapTheSearch(): void
    {
        $lifecycle = new Lifecycle('status', 'open', [
            new LifecycleTransition('close', ['open'], 'closed'),
            new LifecycleTransition('reopen', ['closed'], 'open'),
        ]);

        self::assertSame([], $lifecycle->pathTo('open', 'gone'));
        self::assertSame(['close'], $this->named($lifecycle->pathTo('open', 'closed')));
    }

    /** @return list<string> */
    private function pathTo(string $to, string $from = 'draft'): array
    {
        return $this->named(self::anOrder()->pathTo($from, $to));
    }

    /**
     * @param list<LifecycleTransition> $path
     *
     * @return list<string>
     */
    private function named(array $path): array
    {
        return array_map(static fn (LifecycleTransition $t): string => $t->name, $path);
    }

    /** The order module's own shape, which is the one with two roads to cancelled. */
    private static function anOrder(): Lifecycle
    {
        return new Lifecycle('status', 'draft', [
            new LifecycleTransition('confirm', ['draft'], 'confirmed'),
            new LifecycleTransition('deliver', ['confirmed'], 'delivered'),
            new LifecycleTransition('cancel', ['draft', 'confirmed'], 'cancelled'),
        ]);
    }
}

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

namespace App\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleInstallOrder;
use Xivi\Core\Module\ModuleProvider;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The order a batch install has to happen in, taken out of the blueprints
 * rather than out of whoever typed the list (XIV-72).
 *
 * Blueprints of its own rather than the real modules': the shapes being tested
 * are "needs one thing", "needs two things" and "needs itself, eventually", and
 * a test written against contact/order/invoice would start failing the day one
 * of them grows a requirement for reasons that have nothing to do with sorting.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleInstallOrderTest extends TestCase
{
    public function testARequirementIsInstalledBeforeTheModuleThatNeedsIt(): void
    {
        $order = $this->orderOf(['contact' => [], 'order' => ['contact']]);

        self::assertSame(['contact', 'order'], $order->of(['order', 'contact']));
    }

    /** The case the ticket names: a developer should not have to know this. */
    public function testATwoLevelChainComesOutInOneOrderWhicheverWayItWentIn(): void
    {
        $order = $this->orderOf([
            'contact' => [],
            'article' => [],
            'order' => ['contact'],
            'invoice' => ['order', 'contact'],
        ]);

        foreach ([['invoice', 'order', 'contact', 'article'], ['article', 'contact', 'order', 'invoice']] as $asked) {
            $sorted = $order->of($asked);

            self::assertLessThan(array_search('order', $sorted, true), array_search('contact', $sorted, true));
            self::assertLessThan(array_search('invoice', $sorted, true), array_search('order', $sorted, true));
            self::assertCount(4, $sorted);
        }
    }

    /** Every requested module comes out exactly once, however many things need it. */
    public function testAModuleTwoOthersNeedIsInstalledOnce(): void
    {
        $order = $this->orderOf([
            'contact' => [],
            'order' => ['contact'],
            'invoice' => ['contact'],
        ]);

        self::assertSame(['contact', 'order', 'invoice'], $order->of(['order', 'invoice', 'contact']));
    }

    public function testAskingForOnlyHalfOfAChainIsRefused(): void
    {
        $order = $this->orderOf(['contact' => [], 'order' => ['contact']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Module "order" needs "contact", which is not in the requested set.');

        $order->of(['order']);
    }

    /** What the list should have said, so the refusal can be a corrected command line. */
    public function testTheClosurePullsInWhatWasLeftOut(): void
    {
        $order = $this->orderOf([
            'contact' => [],
            'order' => ['contact'],
            'invoice' => ['order'],
        ]);

        self::assertSame(['contact', 'order', 'invoice'], $order->closureOf(['invoice']));
    }

    public function testAModuleThisBuildDoesNotCarryIsRefused(): void
    {
        $order = $this->orderOf(['contact' => []]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No module "ghost" is available in this build');

        $order->of(['ghost']);
    }

    /**
     * Not reachable through the real blueprints, and the reason this is a
     * recursion rather than a loop: it names the whole circle instead of
     * running until the stack gives out.
     */
    public function testModulesThatNeedEachOtherAreRefusedByName(): void
    {
        $order = $this->orderOf(['a' => ['b'], 'b' => ['a']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('a → b → a');

        $order->of(['a', 'b']);
    }

    public function testNothingAskedForIsNothingInstalled(): void
    {
        self::assertSame([], $this->orderOf(['contact' => []])->of([]));
    }

    /** @param array<string, list<string>> $modules key => what it requires */
    private function orderOf(array $modules): ModuleInstallOrder
    {
        $providers = [];

        foreach ($modules as $key => $requires) {
            $blueprint = new ModuleBlueprint($key, ucfirst($key), $key, [], requires: $requires);

            $providers[] = new class($blueprint) implements ModuleProvider {
                public function __construct(private readonly ModuleBlueprint $blueprint)
                {
                }

                public function blueprint(): ModuleBlueprint
                {
                    return $this->blueprint;
                }
            };
        }

        return new ModuleInstallOrder(new ModuleRegistry($providers));
    }
}

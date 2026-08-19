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

    /**
     * **A module it merely `uses` comes first too, when both were asked for**
     * (XIV-104).
     *
     * The reason is not tidiness: what one module takes from another is decided
     * at install time and never revisited (§6.1), so an order installed before
     * vouchers is an order with no voucher field on it — and nothing goes back
     * and adds one, because §7.2.1 is an offer somebody accepts rather than a
     * retro-fit. Both orderings are asked here, because a sort that only works
     * when the list was already nearly right is not a sort.
     */
    public function testAnOptionalModuleIsInstalledBeforeTheOneThatUsesIt(): void
    {
        $order = $this->orderOf(['contact' => [], 'order' => ['contact'], 'voucher' => []], [
            'order' => ['voucher'],
        ]);

        foreach ([['order', 'voucher', 'contact'], ['contact', 'voucher', 'order']] as $asked) {
            $sorted = $order->of($asked);

            self::assertLessThan(
                array_search('order', $sorted, true),
                array_search('voucher', $sorted, true),
                'whichever way round they were asked for',
            );
            self::assertCount(3, $sorted);
        }
    }

    /**
     * And it is **only** an ordering, never a reason to install something nobody
     * asked for.
     *
     * That is the whole distinction between `uses` and `requires`: a missing
     * requirement is a refusal, and a missing optional module is a customer who
     * did not buy it. A sort that quietly widened the set would install a module
     * — a table, a navigation entry, a licence — that nobody chose.
     */
    public function testAnOptionalModuleIsNeverPulledIn(): void
    {
        $order = $this->orderOf(['contact' => [], 'order' => ['contact'], 'voucher' => []], [
            'order' => ['voucher'],
        ]);

        self::assertSame(['contact', 'order'], $order->of(['order', 'contact']));
        self::assertSame(['contact', 'order'], $order->closureOf(['order']));
    }

    /**
     * Two modules that use each other are installable in either order, so the
     * cycle a `requires` loop is refused for is not one here.
     */
    public function testModulesThatMerelyUseEachOtherAreNotACircle(): void
    {
        $order = $this->orderOf(['left' => [], 'right' => []], ['left' => ['right'], 'right' => ['left']]);

        self::assertCount(2, $order->of(['left', 'right']));
    }

    /**
     * @param array<string, list<string>> $modules key => what it requires
     * @param array<string, list<string>> $uses    and what it merely uses
     */
    private function orderOf(array $modules, array $uses = []): ModuleInstallOrder
    {
        $providers = [];

        foreach ($modules as $key => $requires) {
            $blueprint = new ModuleBlueprint(
                $key,
                ucfirst($key),
                $key,
                [],
                requires: $requires,
                uses: $uses[$key] ?? [],
            );

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

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

namespace App\Tests\Unit\Field;

use App\Controller\FieldController;
use PHPUnit\Framework\TestCase;
use Xivi\Core\Field\ModuleOwnedOptions;
use Xivi\Core\Module\ModuleProvider;

/**
 * Every field option a shipped blueprint sets is either the customer's or the
 * module's, and nothing is both or neither ([XIV-176]).
 *
 * Two lists decide it, written at opposite ends of the codebase:
 * {@see FieldController::PER_TYPE} names every option the editor draws a control
 * for, and {@see ModuleOwnedOptions::DECLARED} names every option no control
 * draws and only the installer writes. Live-reading the second list is only
 * defensible while the two are genuinely disjoint, because the whole argument is
 * that nobody chose those values.
 *
 * **This is the test that stops the next option landing in neither bucket.** An
 * option added to a blueprint with no control and no declaration would be
 * invisible: it would work on a fresh install, never reach an existing tenant,
 * and nothing would say so. `OrderNamesTheVoucherKindsTest` and
 * `SellersNameTheArticleKindsTest` are the same kind of guard on the same kind of
 * silence, and this one lives beside them in `tests/`, the layer allowed to read
 * every module.
 *
 * The blueprints are read rather than the constants, on those tests' reasoning:
 * a declaration that is right about keys nothing uses is a green test about
 * nothing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleOwnedOptionsAreDeclaredTest extends TestCase
{
    /**
     * Nothing is on both lists.
     *
     * An option the editor draws *and* a module overwrites at read time would be
     * a control that silently does nothing, which is the failure §5.4 spends
     * `PER_TYPE` avoiding from the other side.
     */
    public function testTheTwoListsAreDisjoint(): void
    {
        self::assertSame(
            [],
            array_values(array_intersect(ModuleOwnedOptions::DECLARED, array_keys(FieldController::PER_TYPE))),
            'an option is the customer\'s or the module\'s, never both',
        );
    }

    /**
     * And between them they cover every key any blueprint in this build sets.
     *
     * The one that goes red on the next option: a key in neither list has no
     * control, no declaration, and no route to a tenant that already installed
     * the module.
     */
    public function testEveryOptionAShippedBlueprintSetsIsOnOneOfThem(): void
    {
        $known = [...array_keys(FieldController::PER_TYPE), ...ModuleOwnedOptions::DECLARED];
        $used = self::optionKeysInEveryBlueprint();

        self::assertNotSame([], $used, 'the blueprints set options at all');
        self::assertSame(
            [],
            array_values(array_diff($used, $known)),
            'every option key a module ships is either drawn by the editor or declared module-owned',
        );
    }

    /** And what is read live is a subset of what is owned, since owning it is the argument. */
    public function testEveryLiveReadKeyIsModuleOwned(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(ModuleOwnedOptions::LIVE, ModuleOwnedOptions::DECLARED)),
            'a key read from the blueprint is a key nobody chose',
        );
    }

    /**
     * Every option key set anywhere in this build's blueprints, module fields and
     * collection fields alike.
     *
     * @return list<string>
     */
    private static function optionKeysInEveryBlueprint(): array
    {
        $keys = [];

        foreach (self::modules() as $module) {
            $blueprint = $module->blueprint();

            foreach ($blueprint->fields as $field) {
                $keys = [...$keys, ...array_keys($field->options)];
            }

            foreach ($blueprint->collections as $collection) {
                foreach ($collection->fields as $field) {
                    $keys = [...$keys, ...array_keys($field->options)];
                }
            }
        }

        return array_values(array_unique(array_map(strval(...), $keys)));
    }

    /**
     * Every module this build ships, found rather than listed.
     *
     * A hand-written list is what this test exists to stop being the mechanism:
     * a module added to the build and forgotten here would be a guard that is
     * green because it looked at five modules out of six. `ModuleRegistry` is the
     * container's answer to the same question and needs a kernel, which is more
     * than a question about class constants should cost, so the packages are
     * globbed and each provider is instantiated directly, the same thing
     * `OrderNamesTheVoucherKindsTest` does with `new OrderModule()`, once per
     * module instead of once per assertion.
     *
     * @return list<ModuleProvider>
     */
    private static function modules(): array
    {
        $found = [];

        foreach (glob(\dirname(__DIR__, 3) . '/packages/*/src/*Module.php') ?: [] as $file) {
            $class = sprintf('Xivi\\%s\\%s', ucfirst(basename(\dirname($file, 2))), basename($file, '.php'));

            self::assertTrue(class_exists($class), $class . ' is where a module of that name lives');

            $module = new $class();

            self::assertInstanceOf(ModuleProvider::class, $module);

            $found[] = $module;
        }

        self::assertGreaterThan(1, \count($found), 'the packages were found at all');

        return $found;
    }
}

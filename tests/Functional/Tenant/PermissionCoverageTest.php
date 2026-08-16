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

namespace App\Tests\Functional\Tenant;

use App\Tenant\Security\NoModulePermission;
use App\Tenant\Security\PermissionVerbs;
use App\Tenant\Security\StoreAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionVerb;

/**
 * The build failing when a new action ships without a permission (§7.5).
 *
 * This is the half of the design that cannot be achieved by construction. The
 * *catalogue* of permissions needs no maintenance — it is ModuleAction crossed
 * with a customer's installed modules, so nothing has to be seeded or migrated.
 * But nothing in PHP makes somebody annotate a new route, and an unprotected one
 * is invisible: it works, for everybody, which is exactly what it would look like
 * if it were correct.
 *
 * So the surface is defined by the URL rather than by a list. Any route whose
 * path contains `{module}` is a module route, whichever controller grows it,
 * which means a new controller under `/m/{module}` is covered the day it is
 * written. A maintained list of controllers would be the thing that drifts.
 *
 * **Two axes since XIV-6, and the surface still one thing.** The store's routes
 * carry `{module}` too — a *catalogue* key rather than an installed module — and
 * are granted on StoreAction rather than on ModuleAction (§8.4.3). Widening this
 * to "names a permission from either vocabulary" was the honest change: narrowing
 * the surface to exclude `/store` would have made a whole controller invisible to
 * the check that exists because unprotected routes are invisible.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PermissionCoverageTest extends KernelTestCase
{
    /**
     * Actions that are granted and enforced, but not by a route — with why.
     *
     * The escape hatch to {@see testEveryActionIsReachableFromSomeRoute()}, and
     * the same shape as {@see NoModulePermission}: an excuse with a mandatory
     * reason, written next to the check rather than argued in a commit message.
     * It exists because §8.4's three seams are not the only ones. XIV-80 built a
     * write path before the screens that will call it, and its rules hold for an
     * import and a console command that never pass a route at all — so "no route
     * names this verb" is a true statement about follow-ups and not a bug.
     *
     * **An entry here is temporary by intent.** Both of these get a route the day
     * XIV-82 draws the record page, and taking them out of this list is part of
     * that ticket. What stops the hatch rotting open is the test below, which
     * insists an excused action is named somewhere in the application.
     */
    private const array ENFORCED_WITHOUT_A_ROUTE = [
        'follow_up_create' => 'App\Tenant\FollowUp\FollowUpManager, until the record page (XIV-82) has routes.',
        'follow_up_complete' => 'App\Tenant\FollowUp\FollowUpManager, until the record page (XIV-82) has routes.',
    ];

    /**
     * Every route on the module surface declares a permission, or says in as many
     * words why it does not.
     */
    public function testEveryModuleRouteDeclaresAPermission(): void
    {
        $undeclared = [];

        foreach ($this->moduleRoutes() as $name => $method) {
            if ($this->attributes($method, NoModulePermission::class) !== []) {
                continue;
            }

            $granted = $this->attributes($method, IsGranted::class);
            $actions = array_filter(array_map(
                static fn (IsGranted $g): ?PermissionVerb => \is_string($g->attribute)
                    ? PermissionVerbs::tryFrom($g->attribute)
                    : null,
                $granted,
            ));

            if ($actions === []) {
                $undeclared[] = $name;
            }
        }

        self::assertSame([], $undeclared, sprintf(
            "These routes are under /m/{module} and name no permission:\n  %s\n\n"
            ."Add #[IsGranted(ModuleAction::Something->value, subject: 'module')] — or "
            ."#[IsGranted(StoreAction::Something->value, subject: 'store')] on the store's own "
            .'routes — or #[NoModulePermission("why")] if it genuinely is neither.',
            implode("\n  ", $undeclared),
        ));
    }

    /**
     * A typo in the attribute string is caught here rather than at runtime.
     *
     * It matters more than it looks. No voter supports an unknown attribute, so
     * every voter abstains, and abstaining is denied — the route would 403 for
     * everybody including administrators, and read as a permissions problem
     * rather than as the spelling mistake it is.
     */
    public function testEveryPermissionAttributeNamesARealAction(): void
    {
        foreach ($this->moduleRoutes() as $name => $method) {
            foreach ($this->attributes($method, IsGranted::class) as $granted) {
                if (!\is_string($granted->attribute) || str_starts_with($granted->attribute, 'ROLE_')) {
                    continue;
                }

                self::assertNotNull(PermissionVerbs::tryFrom($granted->attribute), sprintf(
                    'Route "%s" is granted on "%s", which is in neither permission vocabulary. Known: %s.',
                    $name,
                    $granted->attribute,
                    implode(', ', [...ModuleAction::values(), ...StoreAction::values()]),
                ));
            }
        }
    }

    /**
     * A module permission needs the module to be about.
     *
     * Without a subject the voter is consulted with null, refuses to support it,
     * and abstains — denied, for everybody, silently. Fails closed, which is the
     * right direction and still a bug.
     */
    public function testEveryModulePermissionNamesItsSubject(): void
    {
        foreach ($this->moduleRoutes() as $name => $method) {
            foreach ($this->attributes($method, IsGranted::class) as $granted) {
                if (!\is_string($granted->attribute) || ModuleAction::tryFrom($granted->attribute) === null) {
                    continue;
                }

                self::assertSame('module', $granted->subject, sprintf(
                    'Route "%s" is granted on a module action but names no module as its subject.',
                    $name,
                ));
            }
        }
    }

    /**
     * The same, for the second axis (§8.4.3, XIV-6).
     *
     * A store verb voted on against a module key is denied for everybody
     * including administrators, because StorePermissionVoter refuses to support
     * it and an abstention is a denial. Same failure as above, one axis over.
     */
    public function testEveryStorePermissionNamesTheStoreAsItsSubject(): void
    {
        foreach ($this->moduleRoutes() as $name => $method) {
            foreach ($this->attributes($method, IsGranted::class) as $granted) {
                if (!\is_string($granted->attribute) || StoreAction::tryFrom($granted->attribute) === null) {
                    continue;
                }

                self::assertSame('store', $granted->subject, sprintf(
                    'Route "%s" is granted on a store action but does not name the store as its subject.',
                    $name,
                ));
            }
        }
    }

    /**
     * The two vocabularies do not overlap.
     *
     * The load-bearing assumption of the whole arrangement: `permission_grant`
     * stores a verb as a bare string, and one column can be read back
     * unambiguously only while no two vocabularies use the same word. A collision
     * would not throw — it would resolve to whichever enum PermissionVerbs tries
     * first, silently, for grants somebody had already been given.
     */
    public function testTheTwoVocabulariesShareNoWord(): void
    {
        self::assertSame(
            [],
            array_values(array_intersect(ModuleAction::values(), StoreAction::values())),
            'A word in both vocabularies makes a stored grant ambiguous — rename one of them.',
        );
    }

    /**
     * No action exists that nothing can do.
     *
     * A case added to either enum and never wired up would appear in the admin
     * screen as something to grant, and granting it would do nothing — a control
     * that lies, which is worse than a red build.
     */
    public function testEveryActionIsReachableFromSomeRoute(): void
    {
        $used = [];

        foreach ($this->moduleRoutes() as $method) {
            foreach ($this->attributes($method, IsGranted::class) as $granted) {
                if (\is_string($granted->attribute) && ($action = PermissionVerbs::tryFrom($granted->attribute)) !== null) {
                    $used[(string) $action->value] = true;
                }
            }
        }

        $unreachable = array_values(array_diff(
            [...ModuleAction::values(), ...StoreAction::values()],
            array_keys($used),
            array_keys(self::ENFORCED_WITHOUT_A_ROUTE),
        ));

        self::assertSame([], $unreachable, sprintf(
            'These actions can be granted but nothing uses them: %s.',
            implode(', ', $unreachable),
        ));
    }

    /**
     * And an action excused from the check above is enforced by *something*.
     *
     * The excuse is the weak point of the previous test, so it is not a bare
     * list: each entry has to be a case the application actually names somewhere
     * outside the enum that declares it. That is what keeps "the UI is coming"
     * from becoming a permanent hole — an action nobody enforces anywhere would
     * still be a control that lies, whether or not it has a route.
     */
    public function testAnActionExcusedFromARouteIsEnforcedSomewhereElse(): void
    {
        $sources = $this->applicationSources();

        foreach (self::ENFORCED_WITHOUT_A_ROUTE as $value => $expectation) {
            $action = ModuleAction::from($value);
            $needle = sprintf('ModuleAction::%s', $action->name);
            $found = [];

            foreach ($sources as $path => $code) {
                if (str_contains($code, $needle)) {
                    $found[] = $path;
                }
            }

            self::assertNotSame([], $found, sprintf(
                "Nothing in src/ or packages/*/src names %s, so \"%s\" is granted and enforced nowhere.\n"
                . 'The excuse said: %s',
                $needle,
                $value,
                $expectation,
            ));
        }
    }

    /** The check is only worth anything if it is looking at something. */
    public function testTheSurfaceIsNotEmpty(): void
    {
        self::assertGreaterThan(5, \count($this->moduleRoutes()));
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Every PHP file the application and the engine are made of.
     *
     * Read off disk rather than out of the container, because what is being
     * looked for is a *mention* — a class that names an action but is not wired
     * to anything yet is exactly the case this covers, and the container has
     * already removed it.
     *
     * @return array<string, string> path => contents
     */
    private function applicationSources(): array
    {
        $root = \dirname(__DIR__, 3);
        $sources = [];

        foreach ([$root . '/src', ...(glob($root . '/packages/*/src') ?: [])] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);

                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $sources;
    }

    /**
     * Every route under `/m/{module}`, as the controller method serving it.
     *
     * @return array<string, \ReflectionMethod> route name => the method
     */
    private function moduleRoutes(): array
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        \assert($router instanceof RouterInterface);

        $routes = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_contains($route->getPath(), '{module}')) {
                continue;
            }

            $controller = $route->getDefault('_controller');

            if (!\is_string($controller) || !str_contains($controller, '::')) {
                continue;
            }

            [$class, $method] = explode('::', $controller, 2);

            if (class_exists($class) && method_exists($class, $method)) {
                $routes[$name] = new \ReflectionMethod($class, $method);
            }
        }

        return $routes;
    }

    /**
     * Attributes of one kind on a method and on the class holding it.
     *
     * Both, because #[IsGranted] on a controller class covers every route in it —
     * which is how the import controller and the metadata editor are written.
     *
     * @template T of object
     *
     * @param class-string<T> $attribute
     *
     * @return list<T>
     */
    private function attributes(\ReflectionMethod $method, string $attribute): array
    {
        $found = [];

        foreach ([...$method->getAttributes($attribute), ...$method->getDeclaringClass()->getAttributes($attribute)] as $reflected) {
            $found[] = $reflected->newInstance();
        }

        return $found;
    }
}

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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Xivi\Core\Permission\ModuleAction;

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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PermissionCoverageTest extends KernelTestCase
{
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
                static fn (IsGranted $g): ?ModuleAction => \is_string($g->attribute)
                    ? ModuleAction::tryFrom($g->attribute)
                    : null,
                $granted,
            ));

            if ($actions === []) {
                $undeclared[] = $name;
            }
        }

        self::assertSame([], $undeclared, sprintf(
            "These routes are under /m/{module} and name no permission:\n  %s\n\n"
            ."Add #[IsGranted(ModuleAction::Something->value, subject: 'module')], or "
            .'#[NoModulePermission("why")] if it genuinely is not one of a module\'s actions.',
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

                self::assertNotNull(ModuleAction::tryFrom($granted->attribute), sprintf(
                    'Route "%s" is granted on "%s", which is not a ModuleAction. Known: %s.',
                    $name,
                    $granted->attribute,
                    implode(', ', ModuleAction::values()),
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
     * No action exists that nothing can do.
     *
     * A case added to the enum and never wired up would appear in the admin
     * screen as something to grant, and granting it would do nothing — a control
     * that lies, which is worse than a red build.
     */
    public function testEveryActionIsReachableFromSomeRoute(): void
    {
        $used = [];

        foreach ($this->moduleRoutes() as $method) {
            foreach ($this->attributes($method, IsGranted::class) as $granted) {
                if (\is_string($granted->attribute) && ($action = ModuleAction::tryFrom($granted->attribute)) !== null) {
                    $used[$action->value] = true;
                }
            }
        }

        $unreachable = array_values(array_diff(ModuleAction::values(), array_keys($used)));

        self::assertSame([], $unreachable, sprintf(
            'These actions can be granted but nothing uses them: %s.',
            implode(', ', $unreachable),
        ));
    }

    /** The check is only worth anything if it is looking at something. */
    public function testTheSurfaceIsNotEmpty(): void
    {
        self::assertGreaterThan(5, \count($this->moduleRoutes()));
    }

    // -- helpers ------------------------------------------------------------

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

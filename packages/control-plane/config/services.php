<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The administration surface's own services (XIV-60).
 *
 * These were part of the application's `App\` resource until the package existed,
 * and the exclusions below are the ones config/services.yaml already carried —
 * moved rather than rewritten, because each of them was an argument somebody had
 * and the arguments did not change when the files did. What is *not* repeated
 * here is the other half of two of them: the classes excluded from a production
 * build are registered again under `when@dev` and `when@test` in the
 * application's config/services.yaml, where the rest of the development-only
 * wiring lives. Splitting an environment decision across two files would be worse
 * than leaving it in the one file that already makes them.
 *
 * Nothing here binds an entity manager or a connection, and that is worth saying
 * because packages/core's own services.php says the opposite. Core is handed a
 * database because it has no way to know which one it is serving; this package
 * always means the control plane's, which is the default entity manager, so
 * autowiring already answers correctly. The one place it does not — a command
 * that writes into a *customer's* database — goes through App\Tenancy to get
 * there, and switching tenants is that service's business rather than this
 * file's.
 */

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->load('Xivi\\ControlPlane\\', __DIR__ . '/../src/')
            ->exclude([
                __DIR__ . '/../src/Entity/',
                __DIR__ . '/../src/XiviControlPlaneBundle.php',
                // Reading a customer's whole shape out loud (XIV-76). Not
                // dangerous and not meaningless, but it hands out every field
                // definition and every hostname an installation has in one call,
                // and it exists to serve development tooling — the MCP extension
                // in packages/xivi-mate, which is itself a dev dependency and
                // absent from a production build. A capability whose only callers
                // cannot exist in production has no reason to be compiled into it.
                __DIR__ . '/../src/Introspection/',
                __DIR__ . '/../src/Command/InspectTenantCommand.php',
                // Rebuilding a tenant from nothing and filling it with fiction
                // (XIV-72). Excluded for a different reason from the dangerous
                // one: tenant:deprovision is the more destructive command of the
                // two and ships, because removing a customer is a real operation.
                // This one is simply meaningless where the records are real.
                __DIR__ . '/../src/Command/ResetTenantCommand.php',
                // Not a service at all, and excluded for a third reason again
                // (XIV-74): it is the little object tenant:reset builds per run to
                // remember how far it got, so it takes a slug and a module list as
                // constructor arguments and the container could not autowire it if
                // it tried. Excluded rather than given defaults, because the
                // defaults would exist to satisfy a compiler pass rather than to
                // mean anything.
                __DIR__ . '/../src/Command/ResetProgress.php',
            ]);
};

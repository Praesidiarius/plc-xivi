<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Xivi\ControlPlane\Command\InspectTenantCommand;
use Xivi\ControlPlane\Command\ResetTenantCommand;
use Xivi\ControlPlane\Introspection\TenantInspector;

/*
 * The administration surface's own services (XIV-60).
 *
 * These were part of the application's `App\` resource until the package existed,
 * and the exclusions below are the ones config/services.yaml already carried —
 * moved rather than rewritten, because each of them was an argument somebody had
 * and the arguments did not change when the files did. The other half of three
 * of them — the dev-and-test registrations that put the excluded classes back —
 * was in the application's config/services.yaml until XIV-96 and is at the top
 * of this file now, for a reason that has nothing to do with tidiness: see the
 * comment there.
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
    // **The other half of three of the exclusions below** (XIV-96).
    //
    // Each of them keeps something out of a *production* build; each was also
    // wanted back in development and in the suite, and that half used to be
    // three lines in the application's `config/services.yaml` under `when@dev`
    // and `when@test`. XIV-60 left them there on the argument that splitting an
    // environment decision across two files is worse than keeping it in the one
    // file that already makes them — which was right until the application had
    // to be buildable without this package at all.
    //
    // It is not a build-target concern. `config/services.yaml` is loaded in
    // every environment, so a class name it cannot resolve is a container that
    // cannot compile, and "the application compiles without the administration
    // surface" would have been true of `prod` and false of `dev` and `test` —
    // which is the shape of a guarantee nobody can rely on. Here, the question
    // does not arise: a build without this package does not read this file
    // either.
    //
    // `$container->env()` rather than `when@dev`, because that key is YAML's and
    // this is PHP; the effect is identical and the values are the ones the
    // application's own file used.
    if (\in_array($container->env(), ['dev', 'test'], true)) {
        $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure()

            // **Public, and that is the load-bearing word** (XIV-76). Mate's MCP
            // server is its own process with its own container; a tool reaches
            // the application by booting the kernel and asking its container for
            // this service by name, which a private service refuses. Public here
            // rather than everywhere for the usual reason — a public service is
            // one the container can never inline or remove — and the blast
            // radius is exactly the environments where the MCP extension can be
            // installed at all.
            //
            // Redundant in `test`, where `test.service_container` reaches
            // private services anyway, and kept identical to `dev` so that the
            // two cannot drift.
            ->set(TenantInspector::class)
                ->public()

            ->set(InspectTenantCommand::class)
            ->set(ResetTenantCommand::class);
    }

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

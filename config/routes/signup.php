<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Xivi\ControlPlane\Routing\SignupRouteLoader;

/*
 * **The public signup surface, if this deployment has one — and if this *build*
 * has one** (XIV-64, XIV-65, XIV-96).
 *
 * This was six lines in `config/routes.yaml` until XIV-96 and it moved for the
 * reason everything else moved in that ticket: `type: xivi_signup` names a route
 * loader that lives in `packages/control-plane`, so a build without the
 * administration surface met an unknown loader type and failed while compiling
 * its routing table. A `class_exists()` is what a YAML import cannot express,
 * and it is the whole reason this file is PHP.
 *
 * **A separate file rather than a conditional inside `routes.yaml`**, because
 * `MicroKernelTrait` imports `config/routes/*.{php,yaml}` *before*
 * `config/routes.yaml` — which is exactly the ordering the feature needs, and it
 * is now the framework's business rather than a comment's:
 *
 *   Symfony matches routes in the order they were loaded, and the application's
 *   own dashboard is at `/` with **no host restriction**, as everything under
 *   `controllers:` is, because a tenant's installation is served on whichever
 *   hostname that customer owns. A landing page at `/` loaded *after* it can
 *   therefore never match: `https://signup.example/` would reach the dashboard,
 *   which asks for a tenant on a host that deliberately resolves none, and
 *   answers with a 500 rather than a form.
 *
 * It is safe in the other direction because every route the loader returns
 * carries the signup host and `https`, so on any other hostname none of them
 * matches at all and nothing loaded later is shadowed anywhere it is wanted.
 *
 * **Why a loader rather than configuration at all.** It decides whether there
 * are any routes: when `SIGNUP_HOST` is empty it returns an empty collection, so
 * "switched off" means the routing table has nothing in it rather than a route
 * that answers 404. It also stamps the configured hostname onto every route it
 * does return, which is why none of the signup controllers carries a `host:` of
 * its own — and Symfony forbids environment placeholders in routing
 * configuration, which is the same constraint that made
 * ControlPlaneRequestListener a listener rather than a `host:` on the operator
 * routes (§8.9).
 *
 * **Reordering used to unbind the host of the whole feature**, and that it no
 * longer does is the work of one compiler pass rather than of this comment:
 * `resource: routing.controllers` loads every class carrying a `#[Route]`
 * attribute, the signup controllers included, *without* a host or a scheme — so
 * the two imports were registering the same route names twice and whichever came
 * last won. Xivi\ControlPlane\DependencyInjection\SignupRoutesComeOnlyFromTheLoaderPass
 * takes those classes out of the framework's loader entirely, which is also what
 * makes "signup off means no route" true of the routing table rather than only
 * of the collection SignupRouteLoader returns. Read that class before touching
 * this file; the bug it describes was live and does not announce itself.
 *
 * The `resource` is a placeholder the loader ignores and cannot be omitted;
 * `type` is what selects it.
 */

return static function (RoutingConfigurator $routes): void {
    // The administration surface is optional at *build* time (§4.4), and this is
    // the routing half of saying so. A customer-facing image has no signup
    // intake and no landing page because it has no loader to ask for them —
    // which is a stronger statement than an empty SIGNUP_HOST, and an
    // independent one: the two switches now stack rather than overlap.
    if (!class_exists(SignupRouteLoader::class)) {
        return;
    }

    $routes->import('.', 'xivi_signup');
};

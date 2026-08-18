<?php

declare(strict_types=1);

use App\Tenant\Security\ActiveUserChecker;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Xivi\ControlPlane\XiviControlPlaneBundle;

/*
 * **Every firewall this installation has, in the order that decides which one
 * takes a request** (XIV-57, XIV-96, docs/architecture.md §8.9 and §4.4).
 *
 * ### Why the firewalls are a PHP file and the rest of `security.yaml` is not
 *
 * Because one of them is conditional and the other three are not, and Symfony
 * will not let that be expressed in two files. `security.firewalls` is declared
 * `disallowNewKeysInSubsequentConfigs()`, so a second configuration source
 * naming a firewall the first did not is refused outright:
 *
 *     You are not allowed to define new elements for path "security.firewalls".
 *     Please define all elements for this path in one config file.
 *
 * That is the wall XIV-96 hit. The rest of the administration surface's
 * configuration — its provider, its entity mapping, its route loader — could be
 * contributed by the package and simply be absent from a build without it. Its
 * firewalls could not: they have to be named here, beside `dev` and `main`, or
 * nowhere.
 *
 * So this file names them and asks the package for their contents. YAML cannot
 * ask a question; PHP can, and the question is the one the ticket is about —
 * **not "is the administration surface switched on" but "is it in this image at
 * all"**. `class_exists()` against a classmap-authoritative autoloader answers
 * that with one array lookup, and it cannot answer yes for a class whose file
 * has been removed.
 *
 * ### The order is the security boundary, and it is now the only thing here
 *
 * Symfony takes the **first** firewall whose matcher accepts a request.
 *
 *   `dev`           — assets, the importmap and the profiler, on every host,
 *                     with no security at all. First, because those paths belong
 *                     to no host and to nobody's data, and a page served without
 *                     its stylesheet is how somebody finds out that this order
 *                     changed.
 *   the control     — host-scoped, and above `main` because `main` has no host
 *   plane's, if     restriction of any kind. Put them below it and an operator's
 *   present         password is checked against `app_user` in whichever
 *                     customer's database the hostname resolved to — the
 *                     cross-tenant leak §8.1 and §8.2 exist to prevent, arriving
 *                     through a line moved in a configuration file.
 *   `main`          — everything else, which is to say every customer.
 *
 * **A comment saying "do not reorder these" is read by everybody except the
 * person who reorders them**, so `ControlPlaneFirewallTest` asks the compiled
 * firewall map which firewall takes a control-plane request and which provider
 * it would authenticate against. The ordering fails the build rather than
 * shipping.
 *
 * ### What is *not* here
 *
 * `password_hashers`, the `tenant_users` provider and the whole of
 * `access_control` are still in `config/packages/security.yaml`. Only the
 * firewalls moved, because only the firewalls had to. `access_control` in
 * particular could not have moved even if it wanted to: it is
 * `cannotBeOverwritten()`, which is the same restriction one notch stricter.
 */

return static function (ContainerConfigurator $container): void {
    $firewalls = [];

    // Assets and dev tooling, on every host. The pattern is a parameter
    // (XIV-57) because ControlPlaneRequestListener has to stand aside for the
    // same set — see config/services.yaml.
    $firewalls['dev'] = [
        'pattern' => '%app.shared_paths%',
        'security' => false,
    ];

    // **The administration surface's firewalls, if this build has any** (XIV-96).
    //
    // The application deliberately does not describe them. It says where they
    // go and asks the package what they are, so that a customer-facing image
    // contains neither the classes nor the configuration that would name them —
    // "not routed" and "not present" are different guarantees, and only the
    // second survives somebody's mistake ([XIV-56] being the live precedent).
    //
    // The package's directory is found through the bundle class rather than
    // spelled out as `packages/control-plane`, so that this keeps working if the
    // package is ever installed from a registry rather than as the path
    // repository it is today. Two levels up from `src/XiviControlPlaneBundle.php`
    // is the package root, which is also what `AbstractBundle::getPath()`
    // computes.
    if (class_exists(XiviControlPlaneBundle::class)) {
        $bundleFile = (new ReflectionClass(XiviControlPlaneBundle::class))->getFileName();
        \assert(\is_string($bundleFile));

        /** @var array<string, array<string, mixed>> $administration */
        $administration = require \dirname($bundleFile, 2) . '/config/firewalls.php';

        $firewalls += $administration;
    }

    $firewalls['main'] = [
        'lazy' => true,
        'provider' => 'tenant_users',
        'entry_point' => 'form_login',

        // The other half of the pair the control plane's `context` describes:
        // the default this would have had anyway, written down so that the
        // separation between an operator's session and a customer's is a
        // decision in configuration rather than a framework default. Its value
        // is the firewall's own name, so saying it changes nothing and
        // invalidates nobody's session.
        'context' => 'main',

        // Without this, User::active is a column nothing reads: a deactivated
        // user signs in exactly as before (§8.5).
        'user_checker' => ActiveUserChecker::class,

        'form_login' => [
            'login_path' => 'login',
            'check_path' => 'login',
            'username_parameter' => 'email',
            'password_parameter' => 'password',
            'enable_csrf' => true,
            'default_target_path' => 'dashboard',
        ],

        // How somebody invited by email gets in the first time (XIV-1).
        //
        // This is the framework's own login link rather than an invitation token
        // table of ours, and the whole argument is in
        // App\Tenant\Security\UserInvitations. The short version: the link is an
        // HMAC over `kernel.secret` and the properties below, so nothing
        // replayable is stored anywhere, and none of the signing, expiry
        // checking or constant-time comparison is ours to get right.
        'login_link' => [
            'check_route' => 'invitation_accept',

            // The 24 hours XIV-1 asked for, in seconds. Long enough for a
            // colleague who reads their mail the next morning, short enough that
            // a forwarded invitation is not a standing key.
            'lifetime' => 86400,

            // What the signature covers, and therefore what invalidates a link
            // when it changes.
            //
            //   invitationSeed — rotated when the link is used and when a second
            //     invitation is sent, which is how a stateless link becomes
            //     single-use and supersedable. It is the load-bearing one; see
            //     User::$invitationSeed.
            //   password — an account that has acquired one has finished with
            //     invitations, so any link still in flight dies with the same
            //     write. Redundant with the seed on the ordinary path and
            //     deliberately kept: two independent reasons a spent invitation
            //     stops working.
            //   id — pins the link to one row, so it cannot survive the address
            //     being reused by a different person later.
            //
            // `max_uses` is deliberately NOT set. Symfony enforces it with a
            // cache pool, and a cache is evictable: an eviction would restore a
            // consumed invitation, silently. The seed does the same job in the
            // tenant's own database, where it cannot evaporate.
            'signature_properties' => ['id', 'password', 'invitationSeed'],

            // Straight to the page that asks them to choose a password. They
            // would be sent there anyway — MustChangePasswordListener holds a new
            // account there — but arriving directly is one redirect rather than
            // two, and the saved target path of whatever they were doing before
            // is not something a fresh invitee has.
            'default_target_path' => 'account',
            'always_use_default_target_path' => true,

            // A refused link lands on the sign-in page, which says what happened
            // and offers the way forward. A blank 403 would tell somebody who has
            // never had an account here nothing at all.
            'login_path' => 'login',
            'failure_path' => 'login',
        ],

        'logout' => [
            'path' => 'logout',
            'target' => 'login',
        ],
    ];

    $container->extension('security', ['firewalls' => $firewalls]);
};

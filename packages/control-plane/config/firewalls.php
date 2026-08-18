<?php

declare(strict_types=1);

use Xivi\ControlPlane\Security\ActiveOperatorChecker;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\SignupHost;

/*
 * **The administration surface's firewalls, in the package that owns them**
 * (XIV-96, docs/architecture.md §3.1 and §4.4).
 *
 * These two blocks were in the application's `config/packages/security.yaml`
 * until this ticket, and moving them out is most of what made a customer-facing
 * build possible. XIV-60 left them there and wrote the consequence down: the
 * application's security configuration named
 * `Xivi\ControlPlane\Security\ControlPlaneHost` as a request matcher and
 * `Xivi\ControlPlane\Entity\Operator` as a provider's class, so the container
 * **did not compile without this package**. Dropping the Composer requirement
 * was therefore not enough to build an image without the administration surface:
 * the build failed before anything was served, and it failed inside the security
 * configuration, which is the last place anybody wants to be improvising under
 * deadline.
 *
 * ### Why this file returns an array instead of prepending one
 *
 * Every other thing this package contributes to the application's configuration
 * is prepended from {@see \Xivi\ControlPlane\XiviControlPlaneBundle::prependExtension()}
 * — the `operators` provider, the entity mapping — and that is the shape this
 * wanted to be too. Symfony refuses: `security.firewalls` is declared
 * `disallowNewKeysInSubsequentConfigs()`, so a second configuration source
 * naming a firewall the first one did not throws
 *
 *     You are not allowed to define new elements for path "security.firewalls".
 *     Please define all elements for this path in one config file.
 *
 * and it throws in both directions, because a prepended config is merely the
 * *first* config rather than a privileged one. All four firewalls have to be
 * written by one source. So the application writes them, in
 * `config/packages/security_firewalls.php`, and asks this file for the two it
 * does not own — which keeps the property that matters: **a build without this
 * package has no operator firewall, because the file that describes one is not
 * in the image either.** What the application carries is the seam, not the
 * surface.
 *
 * ### The ordering is the security boundary (XIV-57, §8.9)
 *
 * The caller splices these between `dev` and `main`, and that position is not
 * presentation. Symfony takes the first firewall whose matcher accepts a
 * request; `main` has no host restriction of any kind, so it accepts
 * everything. Below `main`, a control-plane sign-in would be answered by a
 * firewall whose provider is `tenant_users` — which is to say by whichever
 * customer's database the hostname resolved to, which is precisely the
 * cross-tenant leak §8.1 and §8.2 exist to make impossible, arriving through a
 * line moved in a configuration file. `ControlPlaneFirewallTest` asks the
 * compiled firewall map rather than trusting this paragraph.
 *
 * Below `dev` for a reason that is smaller and just as real. That firewall's
 * pattern is `%app.shared_paths%` and its `security: false` is what serves the
 * operator console's own stylesheets: {@see ControlPlaneHost} matches on host
 * alone, so a `/assets/…` request on the control-plane host would be claimed
 * here and then refused by the `^/` access rule. The symptom would be a login
 * page with no CSS and nothing in any log pointing at a firewall. Splicing
 * between `dev` and `main` is what keeps both properties, and it is why the
 * caller splices rather than letting the bundle prepend these.
 */

return [
    // **The control plane.** `request_matcher` rather than `host:`, because
    // `host:` is a regular expression and a hostname is full of dots that would
    // each match any character — `control.example.com` would also accept
    // `controlXexample.com`, a name somebody else can own. ControlPlaneHost
    // compares normalised strings, through the same normalisation tenancy uses
    // to decide that this host resolves no tenant.
    'control_plane' => [
        'lazy' => true,
        'request_matcher' => ControlPlaneHost::class,
        'provider' => 'operators',
        'entry_point' => 'form_login',

        // Without this, Operator::active is a column nothing reads: a revoked
        // operator signs in exactly as before (XIV-92, §8.9). The mirror of
        // `main`'s `App\Tenant\Security\ActiveUserChecker`, and a separate class
        // rather than one checker taught about both entities — the two firewalls
        // are kept apart on purpose, and a single object holding the rule for
        // both sides of that boundary would need deptrac to be told the tenant
        // application may reach into the control-plane package.
        //
        // **A checker is only half of it, on both sides.** It is consulted when
        // somebody signs in and never when a session is restored, so
        // `Xivi\ControlPlane\EventListener\RevokedOperatorListener` ends a
        // session that already exists. The comment on `main`'s checker says the
        // same thing and the reason is the same framework behaviour.
        'user_checker' => ActiveOperatorChecker::class,

        // **Said out loud rather than inherited** (XIV-57). Symfony defaults a
        // firewall's context to its own name, so these two are already separate
        // and a session minted here is already not a token `main` would restore.
        // Written down because "already" is doing the work of a security
        // boundary in that sentence: an operator session and a tenant session
        // must never be interchangeable, and a property that holds because
        // nobody has changed a default is one line of somebody else's release
        // notes away from not holding.
        'context' => 'control_plane',

        'form_login' => [
            'login_path' => 'control_plane_login',
            'check_path' => 'control_plane_login',
            'username_parameter' => 'email',
            'password_parameter' => 'password',
            'enable_csrf' => true,
            'default_target_path' => 'control_plane_home',
        ],

        // No `login_link` here, deliberately. Invitations exist because a tenant
        // administrator has colleagues to let in and no way to hand them a
        // password (XIV-1, §8.8); an operator is created at a console by
        // somebody who already has one, and a mailed link that admits somebody
        // to every customer's registry is not a convenience worth inventing
        // before anybody has asked for it.
        'logout' => [
            'path' => 'control_plane_logout',
            'target' => 'control_plane_login',
        ],
    ],

    // **The public signup endpoint, and it has no security at all on purpose**
    // (XIV-64, §8.12).
    //
    // Below the control plane and above `main`, and both halves of that matter.
    //
    // Above `main` because `main` has no host restriction: without this block a
    // request here would land in a firewall whose provider is `tenant_users`,
    // looking people up in whichever customer's database the hostname resolved
    // to — on a host where none resolves. Nothing would come of it in practice,
    // because this endpoint carries no session and asks for no user. "Nothing
    // would come of it in practice" is the wrong standard for a boundary, so the
    // host gets a firewall that runs no authentication machinery: no provider,
    // no session, nothing to hand a stray cookie to.
    //
    // Below `control_plane` because the two hostnames must differ and
    // SignupRouteLoader refuses to build a routing table when they do not — but
    // if that refusal is ever removed, this ordering decides which way the
    // mistake falls. Control plane first means a misconfigured deployment gets
    // an operator console that still demands a password. The other order means
    // an operator console with `security: false` in front of it. The order of
    // the two keys in this array is what says so, exactly as the order of the
    // two blocks in security.yaml used to.
    //
    // **The endpoint is not unauthenticated.**
    // Xivi\ControlPlane\Signup\SignupApiKey checks a shared secret in constant
    // time, in the controller, and refuses when none is configured. A Symfony
    // authenticator was the alternative: `access_token` wants a token handler
    // producing a UserBadge and a provider to resolve it against, which means
    // inventing a user for a caller that is a *deployment* rather than a person
    // — a great deal more configuration for one hash_equals.
    //
    // `request_matcher` rather than `host:` for the reason the block above
    // gives: that key is a regular expression in which every dot matches any
    // character. SignupHost compares normalised strings, and matches nothing at
    // all when signup is switched off.
    'signup' => [
        'request_matcher' => SignupHost::class,
        'security' => false,
    ],
];

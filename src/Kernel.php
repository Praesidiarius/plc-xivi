<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * **Do not instantiate a bundle whose class is not in this image**
     * (XIV-96, XIV-111, docs/architecture/deployment.md §4.4).
     *
     * ## What this replaces, and why the replacement is not merely tidier
     *
     * `config/bundles.php` used to carry an `if (class_exists(…))` around the
     * administration surface's bundle. That worked, and it was in the wrong
     * file: Flex regenerates `bundles.php` from its own template when a package
     * is added, so the guard was deleted by `composer update xivi/voucher`
     * ([XIV-103]) and only caught by somebody reading the diff.
     *
     * The rule was also a general one wearing a special case's clothes. Nothing
     * about "do not construct a class that is not here" belongs to the control
     * plane, and nothing about it belongs in a generated file. So the rule is
     * here, in a file no recipe rewrites, and the one thing it is currently
     * about is a datum in `config/optional_bundles.php`. The property that makes
     * this the right answer rather than a neater one: **a Flex rewrite of
     * `config/bundles.php` stops being a hazard**, because the file it produces
     * — a plain array with the control-plane line unconditional — is the file we
     * want. That is strictly better than detecting the rewrite, which was the
     * other way to close this and which would have needed somebody to react to
     * an alarm rather than needing nothing to happen at all.
     *
     * ## `class_exists()`, and why not a flag
     *
     * The question is **"is this class in the image"**, never "is the
     * administration surface switched on". A `%env()%` flag would mean the code
     * is present and disabled, which is one misconfiguration away from being
     * served; [XIV-56] is the live precedent for the difference. In a production
     * build the autoloader is classmap-authoritative, so this is one array
     * lookup with no filesystem access — and it cannot answer "yes" for a class
     * whose file has been removed, which is exactly the question being asked.
     *
     * ## An explicit list, because the failure this introduces is silence
     *
     * A bundle skipped because it is not in the image looks identical to a
     * bundle skipped because somebody has not finished running `composer
     * install`. "Skip anything missing" would therefore turn a half-installed
     * checkout into an application that boots, serves, and is quietly missing a
     * module — the worst shape a failure can take here.
     *
     * So the list is enumerated and short, and **anything not on it that goes
     * missing still fatals**, on `new $class()` inside the trait's
     * `registerBundles()`, exactly as it did before this change.
     * `tests/Unit/Deployment/OnlyOptionalBundlesAreSkippedTest.php` plants both
     * halves of that.
     *
     * ## The complaint is louder outside production, which inverts [XIV-61]
     *
     * `PlaceholderSecretGuard` stands down outside `prod` because the risk it
     * covers is production-only. This is the mirror image: the *legitimate*
     * absence is production-only, because `frankenphp_public` is the only build
     * that removes a package. An optional bundle missing from a `dev` or `test`
     * checkout is therefore always suspicious — nobody assembles a reduced
     * development environment — so it says so, at `E_USER_WARNING`, naming the
     * command that fixes it.
     *
     * A warning and not an exception, because the application genuinely works
     * without the administration surface and turning a working boot into a fatal
     * one would punish the one person who has legitimately trimmed a checkout.
     * `phpunit.dist.xml` sets `failOnWarning`, so in the test environment it is
     * effectively fatal anyway, which is the right severity there.
     *
     * ## How this overrides `registerBundles()` without reimplementing it
     *
     * `MicroKernelTrait::registerBundles()` is a generator: it reads
     * `getBundlesDefinition()`, applies the `['all' => true]` / per-environment
     * filter, and yields `new $class()`. Wrapping it is useless — the
     * instantiation happens *inside* the generator, so a filter applied to what
     * it yields runs after the fatal, and a generator that has thrown cannot be
     * resumed. Copying its four lines would mean owning Symfony's
     * environment-matching semantics for ever.
     *
     * `getBundlesDefinition()` is the seam that avoids both. It is the private
     * method the trait reads the array from, `MicroKernelTrait` already aliases
     * it to `doGetBundlesDefinition` for exactly this kind of decoration, and a
     * method declared on the class takes precedence over the one a trait
     * imports. So the trait keeps reading the file, keeps resolving
     * `#[RequiredBundle]`, and keeps deciding which environments a bundle is
     * for; this only removes entries from the array before any of that runs.
     *
     * It also filters the `.kernel.bundles_definition` container parameter,
     * which is built from the same method — so a build without the package
     * cannot leak the class name into the compiled container through it. That
     * matters more than it looks: the `frankenphp_public` build refuses to
     * finish if anything under `var/cache/` still names `Xivi\ControlPlane`.
     *
     * **The one thing this loses**, written down because it is invisible: the
     * bundle list is cached at `var/cache/<env>/….bundles.php`, and Symfony
     * invalidates that cache against `config/bundles.php`'s modification time
     * only. Editing `config/optional_bundles.php` on its own therefore needs a
     * `cache:clear`. In practice an entry is only ever added alongside the
     * bundle it is about, which touches `bundles.php` in the same commit, and
     * every production build compiles its cache from nothing.
     *
     * @return array<class-string, array<string, bool>>
     */
    private function getBundlesDefinition(): array
    {
        $definition = $this->doGetBundlesDefinition();

        foreach ($this->getOptionalBundles() as $class) {
            if (!isset($definition[$class]) || class_exists($class)) {
                continue;
            }

            unset($definition[$class]);

            if ('prod' !== $this->environment) {
                trigger_error(
                    \sprintf(
                        'The optional bundle "%s" is not in this build, so it was not registered. That is expected '
                        . 'only in the customer-facing image (docs/architecture/deployment.md §4.4); in "%s" it almost always '
                        . 'means `composer install` has not finished. Run `bin/compose exec php composer install`.',
                        $class,
                        $this->environment,
                    ),
                    \E_USER_WARNING,
                );
            }
        }

        return $definition;
    }

    /**
     * The bundles `config/optional_bundles.php` declares may be absent.
     *
     * Read from `config/` rather than written out as a constant here for one
     * reason, and it is a boundary rather than a preference: `deptrac.yaml` says
     * the application may not depend on `Xivi\ControlPlane`, and it means it — a
     * `::class` on the bundle in this file is collected as a dependency and
     * fails `composer deptrac`. That was measured rather than assumed, by
     * writing one and watching it report "App\Kernel must not depend on
     * Xivi\ControlPlane\XiviControlPlaneBundle".
     *
     * Spelling the name as a plain string would have got past the collector, and
     * that is precisely the reason not to: a boundary check evaded with a quoted
     * string is a boundary that has stopped being checked. `config/` is where
     * the application is already allowed to name the package — all three seams
     * §4.4 lists are there, and `ControlPlaneIsOptionalAtBuildTimeTest` reads
     * that directory and no other — so the list stays where the enforcement can
     * see it, next to the `bundles.php` whose reader will be looking for it.
     *
     * A missing file is an empty list rather than an error, because a kernel
     * that could not boot without an optional-bundle declaration would have made
     * the optional thing mandatory.
     *
     * @return list<class-string>
     */
    private function getOptionalBundles(): array
    {
        $path = $this->getConfigDir() . '/optional_bundles.php';

        if (!is_file($path)) {
            return [];
        }

        /** @var list<class-string> $optional */
        $optional = require $path;

        return $optional;
    }
}

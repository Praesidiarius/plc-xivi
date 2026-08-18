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

namespace App\Tests\Unit\Deployment;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * **The kernel skips a bundle that may legitimately be absent, and nothing
 * else** (XIV-111, docs/architecture.md §4.4).
 *
 * ## The risk this test exists to cover is silence
 *
 * [XIV-111] moved the `class_exists()` that keeps the administration surface
 * optional out of `config/bundles.php` — a file Symfony Flex regenerates
 * wholesale — and into `App\Kernel`, working from the list in
 * `config/optional_bundles.php`. The move is safe in the direction anybody would
 * check: the customer-facing image still builds, and `bin/ci` builds it.
 *
 * It is the *other* direction that needed a test written for it. A bundle
 * skipped because it is not in the image looks exactly like a bundle skipped
 * because somebody's `composer install` did not finish, and the second must stay
 * fatal. A kernel that quietly shrugged at any missing bundle would turn a
 * half-installed checkout into an application that boots, serves, and is missing
 * a module — and would pass every test in this repository while doing it.
 *
 * So the two halves are planted here side by side: **on the list, skipped;
 * off the list, `Error`.** Delete the `isset()`/list check in
 * `Kernel::getBundlesDefinition()` and the second test fails; delete the
 * `unset()` and the first does.
 *
 * ## Why a fixture project directory rather than this repository's own
 *
 * Because the interesting case cannot be staged here: `packages/control-plane`
 * is installed in every development checkout, so the class is always present and
 * "what happens when it is not" is unaskable without deleting it. `App\Kernel`
 * reads `bundles.php` and `optional_bundles.php` from `getConfigDir()`, which is
 * derived from `getProjectDir()`, and that one *is* public and overridable — so
 * a throwaway directory with two config files in it puts the kernel in the
 * position the `frankenphp_public` image puts it in, without touching anything
 * real.
 *
 * `registerBundles()` and not `boot()`, deliberately. Booting would compile a
 * container, which needs a great deal more of a project than two files, and the
 * decision under test is made before any of that: the trait's generator either
 * reaches `new $class()` or it does not.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OnlyOptionalBundlesAreSkippedTest extends TestCase
{
    /**
     * A class this build does not have and never will.
     *
     * Under `App\Tests\` so that Composer's PSR-4 map does resolve the
     * namespace, looks for the file and finds nothing — the same shape as a
     * package whose directory has been removed, rather than a namespace the
     * autoloader ignores outright.
     */
    private const string ABSENT_BUNDLE = 'App\Tests\Fixtures\Deployment\NotInThisBuildBundle';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/xivi-optional-bundles-' . bin2hex(random_bytes(8));

        (new Filesystem())->mkdir($this->projectDir . '/config');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    /**
     * The property the customer-facing image depends on: a bundle declared
     * optional, and absent, is simply not registered.
     */
    public function testAnAbsentBundleOnTheListIsSkipped(): void
    {
        $this->writeConfiguration(
            bundles: [FrameworkBundle::class, self::ABSENT_BUNDLE],
            optional: [self::ABSENT_BUNDLE],
        );

        $registered = $this->registeredBundleClasses('prod');

        self::assertNotContains(
            self::ABSENT_BUNDLE,
            $registered,
            'A bundle listed in config/optional_bundles.php and missing from the build must be skipped; '
            . 'this is what makes --target frankenphp_public possible at all.',
        );
        self::assertContains(FrameworkBundle::class, $registered, 'and everything else must still be there.');
    }

    /**
     * And the half that is the point of the list being a list.
     *
     * Same fixture, same absent class, one difference: it is not declared
     * optional. The kernel must behave exactly as it did before [XIV-111] — a
     * fatal, naming the class, at the moment the trait tries to construct it.
     */
    public function testAnAbsentBundleThatIsNotOnTheListStillFatals(): void
    {
        $this->writeConfiguration(
            bundles: [FrameworkBundle::class, self::ABSENT_BUNDLE],
            optional: [],
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessage(self::ABSENT_BUNDLE);

        $this->registeredBundleClasses('prod');
    }

    /**
     * A present bundle is registered whether or not it is on the list, which is
     * the case every development checkout and the internal image are in.
     *
     * Worth asserting rather than assuming: an over-eager filter that dropped
     * everything named in `optional_bundles.php` would leave the administration
     * surface out of the image that is supposed to have it, and no other test
     * here would notice — the container would compile, and the operator console
     * would simply not be routed.
     */
    public function testAnOptionalBundleThatIsPresentIsStillRegistered(): void
    {
        $this->writeConfiguration(
            bundles: [FrameworkBundle::class],
            optional: [FrameworkBundle::class],
        );

        self::assertContains(FrameworkBundle::class, $this->registeredBundleClasses('prod'));
    }

    /**
     * Outside production the skip is announced, because outside production it is
     * almost certainly not what anybody meant.
     *
     * This inverts [XIV-61]'s `PlaceholderSecretGuard`, which stands down outside
     * `prod` because the risk it covers is production-only. Here the *legitimate*
     * absence is production-only — `frankenphp_public` is the only build that
     * removes a package — so a `dev` or `test` checkout missing an optional
     * bundle is a broken install rather than a deployment choice.
     */
    public function testTheSkipIsAnnouncedOutsideProduction(): void
    {
        $this->writeConfiguration(
            bundles: [FrameworkBundle::class, self::ABSENT_BUNDLE],
            optional: [self::ABSENT_BUNDLE],
        );

        $warnings = [];

        set_error_handler(
            static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            \E_USER_WARNING,
        );

        try {
            $registered = $this->registeredBundleClasses('dev');
        } finally {
            restore_error_handler();
        }

        self::assertNotContains(self::ABSENT_BUNDLE, $registered);
        self::assertCount(1, $warnings, 'A skip outside prod must say so exactly once, per bundle skipped.');
        self::assertStringContainsString(self::ABSENT_BUNDLE, $warnings[0]);
        self::assertStringContainsString('composer install', $warnings[0], 'It has to name the way out.');
    }

    /** And in production it is silent, because there it is the design. */
    public function testTheSkipIsSilentInProduction(): void
    {
        $this->writeConfiguration(
            bundles: [FrameworkBundle::class, self::ABSENT_BUNDLE],
            optional: [self::ABSENT_BUNDLE],
        );

        $warnings = [];

        set_error_handler(
            static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            \E_USER_WARNING,
        );

        try {
            $this->registeredBundleClasses('prod');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
    }

    /**
     * The real repository's own list, checked against the checkout it is in.
     *
     * Two things at once, and both would otherwise be silent. Every class the
     * list names must exist here — a typo in `optional_bundles.php` would
     * otherwise mean a bundle *nothing* registers, in every image, discovered
     * when a screen 404s. And the list must stay short: it is a list of promises
     * that a missing class is expected, and each one is a way for a broken
     * install to look like a working one, so growing it is a decision somebody
     * has to make in a commit that changes this number.
     */
    public function testTheRepositoryDeclaresOnlyBundlesItActuallyHas(): void
    {
        $optional = require \dirname(__DIR__, 3) . '/config/optional_bundles.php';

        self::assertIsArray($optional);
        self::assertCount(
            1,
            $optional,
            'config/optional_bundles.php has grown. That is allowed, and it is a decision: read the file, '
            . 'then change this number in the same commit.',
        );

        foreach ($optional as $class) {
            self::assertIsString($class);
            self::assertTrue(
                class_exists($class),
                \sprintf(
                    '%s is declared optional and does not exist in this checkout. A development checkout has every '
                    . 'package, so this is a typo rather than a build target — and a typo here silently unregisters '
                    . 'a bundle everywhere.',
                    $class,
                ),
            );
        }
    }

    /**
     * Writes the two files `App\Kernel` reads, in the shape the real ones have:
     * `bundles.php` a map of class to environments, `optional_bundles.php` a
     * flat list of class names.
     *
     * @param list<string> $bundles
     * @param list<string> $optional
     */
    private function writeConfiguration(array $bundles, array $optional): void
    {
        $filesystem = new Filesystem();

        $filesystem->dumpFile(
            $this->projectDir . '/config/bundles.php',
            "<?php\n\nreturn " . var_export(array_fill_keys($bundles, ['all' => true]), true) . ";\n",
        );

        $filesystem->dumpFile(
            $this->projectDir . '/config/optional_bundles.php',
            "<?php\n\nreturn " . var_export($optional, true) . ";\n",
        );
    }

    /**
     * Runs the kernel's own `registerBundles()` against the fixture directory
     * and returns the classes it produced.
     *
     * The anonymous subclass changes exactly one thing — where the project is —
     * so everything under test is `App\Kernel`'s, unmocked.
     *
     * **The result contains more than the fixture names**, which is worth
     * knowing before reading an assertion here: Symfony resolves
     * `#[RequiredBundle]` while it builds the definition, so asking for
     * `FrameworkBundle` also produces `ServicesBundle` and, where it is
     * installed, `ConsoleBundle`. That resolution is exactly the work
     * `Kernel::getBundlesDefinition()` is careful not to reimplement, and its
     * showing up here is the evidence that it still runs. So these tests ask
     * what is and is not in the list rather than pinning the whole of it.
     *
     * @return list<class-string>
     */
    private function registeredBundleClasses(string $environment): array
    {
        $kernel = new class($environment, $this->projectDir) extends Kernel {
            public function __construct(
                string $environment,
                private readonly string $fixtureProjectDir,
            ) {
                parent::__construct($environment, false);
            }

            public function getProjectDir(): string
            {
                return $this->fixtureProjectDir;
            }
        };

        $classes = [];

        foreach ($kernel->registerBundles() as $bundle) {
            self::assertIsObject($bundle);

            $classes[] = $bundle::class;
        }

        return $classes;
    }
}

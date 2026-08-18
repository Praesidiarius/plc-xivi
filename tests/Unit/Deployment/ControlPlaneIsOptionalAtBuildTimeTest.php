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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * **The application's configuration must not name a class the customer-facing
 * build does not have** (XIV-96, docs/architecture.md §4.4).
 *
 * ## What this is for, and why deptrac is not it
 *
 * `deptrac.yaml` has stated since XIV-60 that the application may not depend on
 * the control plane, and it enforces that over `src/` and `packages/` — which is
 * to say over *code*. The thing that actually stopped a customer-facing image
 * from being built was not code. It was four lines of configuration:
 * `security.yaml` naming `ControlPlaneHost` and `Operator`, `doctrine.yaml`
 * naming the package's entity directory, `routes.yaml` naming a route loader
 * type the package registers, and `bundles.php` naming the bundle itself. Every
 * one of them compiles into the container, none of them is an import, and
 * deptrac does not read YAML.
 *
 * So this is the same rule said about the other half of the application, and it
 * is a test rather than a comment for the reason this codebase keeps
 * rediscovering: a rule nothing checks is a rule that is true until somebody is
 * in a hurry. **The way it will be broken is not by malice but by convenience** —
 * one `user_checker:` added to `security.yaml` because that is where the other
 * firewall's is, and the customer-facing build stops existing, in a way that
 * nothing notices until the next release is cut.
 *
 * ## The seams, and why they are allowed
 *
 * Four application files do name the package, and each is a deliberate seam
 * rather than a dependency. They come in two kinds, and [XIV-111] is what made
 * the second kind exist.
 *
 * **The guarded ones ask a question.** `config/packages/security_firewalls.php`
 * splices the administration surface's firewalls between `dev` and `main`,
 * because Symfony insists every firewall be named by one configuration source;
 * `config/routes/signup.php` imports the signup route loader, whose *type* only
 * exists when the package does. Both are safe because both ask
 * `class_exists()` — whether the class is *in this build*, which a
 * classmap-authoritative autoloader answers with one array lookup and cannot
 * answer "yes" to for a file that has been removed.
 *
 * **The declared ones state a fact and let somebody else act on it.**
 * `config/bundles.php` names the bundle unconditionally, and
 * `config/optional_bundles.php` says that that bundle may be absent;
 * `App\Kernel::getBundlesDefinition()` is what asks `class_exists()` about it.
 * That pair exists because `bundles.php` is *generated* — Flex rewrites it from
 * its own template when a package is added, and did, deleting the conditional
 * that used to live there ([XIV-103], [XIV-111]). Making the file declarative is
 * what stops that rewrite from being a hazard, and the price is that its safety
 * is no longer visible in the file itself, which is what
 * {@see OnlyOptionalBundlesAreSkippedTest} is for.
 *
 * Both lists are written out below rather than derived, because that is the
 * point: adding a fifth is a decision somebody should have to make on purpose,
 * in a commit that changes this file and says why.
 *
 * ## Comments are exempt, deliberately
 *
 * Half the configuration in this repository explains itself by naming the class
 * on the other side of a boundary, and a rule that forbade that would be a rule
 * that made the configuration less legible in order to pass. So PHP files are
 * read with PHP's own lexer and their comment tokens dropped — the same
 * technique {@see \App\Tests\Unit\TenantMigrationsAreAdditiveTest} uses and for
 * the same reason — and YAML files have their `#` lines stripped.
 *
 * The YAML half is the blunter of the two: a `#` inside a quoted string would be
 * treated as the start of a comment. That is the harmless direction — it can
 * only cause this test to miss something, never to fail wrongly — and no
 * configuration file here has one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ControlPlaneIsOptionalAtBuildTimeTest extends TestCase
{
    /** The namespace no application configuration may resolve, except at a seam. */
    private const string NAMESPACE_PATTERN = '/Xivi\\\\+ControlPlane/';

    /**
     * The files allowed to name it by asking whether it is in this build. Each
     * of these must contain a `class_exists()`; see the class docblock.
     *
     * @var list<string>
     */
    private const array GUARDED_SEAMS = [
        'packages/security_firewalls.php',
        'routes/signup.php',
    ];

    /**
     * The pair that names it without asking anything, and only makes sense read
     * together (XIV-111).
     *
     * `bundles.php` is generated by Flex and must therefore stay a plain array
     * that a regeneration would reproduce; `optional_bundles.php` is the
     * hand-written half that declares the bundle may be absent, and the kernel
     * is what acts on the declaration.
     *
     * @var list<string>
     */
    private const array DECLARED_SEAMS = [
        'bundles.php',
        'optional_bundles.php',
    ];

    /**
     * The rule itself: every application configuration file either says nothing
     * about the control plane once its comments are gone, or is one of the
     * seams.
     */
    public function testNoApplicationConfigurationNamesTheControlPlaneOutsideASeam(): void
    {
        $offenders = [];

        foreach ($this->configurationFiles() as $relative => $code) {
            if (preg_match(self::NAMESPACE_PATTERN, $code) !== 1) {
                continue;
            }

            if (!\in_array($relative, [...self::GUARDED_SEAMS, ...self::DECLARED_SEAMS], true)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These files name Xivi\\ControlPlane in configuration the application always loads, so a build\n"
            . "without the administration surface cannot compile its container (XIV-96, §4.4). Either move the\n"
            . "declaration into packages/control-plane, or make it a guarded seam and add it to\n"
            . "self::GUARDED_SEAMS:\n  "
            . implode("\n  ", $offenders),
        );
    }

    /**
     * And the other half, which is the half that would rot silently: a seam that
     * stopped guarding would still pass the test above.
     *
     * A seam without its `class_exists()` is not a seam. It is an unconditional
     * reference in a file this test has been told to ignore, which is worse than
     * an unconditional reference anywhere else.
     */
    public function testEveryGuardedSeamAsksWhetherTheClassIsInThisBuild(): void
    {
        foreach (self::GUARDED_SEAMS as $seam) {
            $path = $this->configDirectory() . '/' . $seam;

            self::assertFileExists($path, sprintf('%s is listed as a seam and is not there.', $seam));

            $contents = file_get_contents($path);
            self::assertIsString($contents);

            self::assertStringContainsString(
                'class_exists(',
                $contents,
                sprintf(
                    '%s names Xivi\\ControlPlane without asking whether it is in this build. '
                    . 'A seam that does not guard is an unconditional dependency in a file nothing checks.',
                    $seam,
                ),
            );
        }
    }

    /**
     * The declared seam's own version of the same rot, and it is the one
     * [XIV-111] introduced.
     *
     * `config/bundles.php` now names the bundle unconditionally, which is only
     * safe because `config/optional_bundles.php` says the class may be absent
     * and `App\Kernel` acts on that. Add a second control-plane bundle to
     * `bundles.php` and forget the second half, and the customer-facing image
     * stops building — with a fatal in the kernel, from a file that reads
     * perfectly.
     *
     * So the invariant is stated as the relationship rather than as a string
     * search: **every control-plane class `bundles.php` names is declared
     * optional.** Read from the files themselves, because reading the kernel's
     * copy would only prove the kernel agrees with itself.
     */
    public function testEveryControlPlaneBundleIsDeclaredOptional(): void
    {
        $bundles = require $this->configDirectory() . '/bundles.php';
        $optional = require $this->configDirectory() . '/optional_bundles.php';

        self::assertIsArray($bundles);
        self::assertIsArray($optional);

        $named = array_values(array_filter(
            array_keys($bundles),
            static fn (mixed $class): bool => \is_string($class)
                && preg_match(self::NAMESPACE_PATTERN, $class) === 1,
        ));

        self::assertNotSame([], $named, 'config/bundles.php stopped naming the administration surface at all, '
            . 'which means the internal image has lost it. That is not what this change was for.');

        foreach ($named as $class) {
            self::assertContains(
                $class,
                $optional,
                sprintf(
                    '%s is registered by config/bundles.php and is not in config/optional_bundles.php, so the '
                    . 'kernel will try to construct it in a build that does not have it (XIV-96, §4.4). '
                    . 'The customer-facing image stops building the moment this is true.',
                    $class,
                ),
            );
        }
    }

    /**
     * And the property that made the move worth making: `config/bundles.php`
     * contains nothing a Flex recipe could destroy by regenerating it.
     *
     * The failure this closes is not hypothetical. `composer update
     * xivi/voucher` ([XIV-103]) rewrote this file from Flex's template and
     * deleted the `if (class_exists(…))` that used to keep the administration
     * surface optional; it was caught by somebody reading a diff, which is not a
     * control. **Adding a package is not an operation that promises to leave
     * `config/bundles.php` alone**, so the answer is a file whose regenerated
     * form is the form we want.
     *
     * Checked by asking for parentheses rather than for `if` or `class_exists`,
     * which is blunter on purpose: a purely declarative array of
     * `Foo::class => ['all' => true]` has none at all, so anything that calls,
     * branches or computes shows up whatever it is called. Comments are dropped
     * first — this file's own explains that it is generated, and it is a comment
     * precisely because losing it costs nothing.
     */
    public function testBundlesPhpIsSomethingFlexCouldRegenerateWithoutHarm(): void
    {
        $contents = file_get_contents($this->configDirectory() . '/bundles.php');
        self::assertIsString($contents);

        $logic = [];

        foreach (token_get_all($contents) as $token) {
            if (\is_array($token)) {
                continue;
            }

            if ($token === '(' || $token === ')') {
                $logic[] = $token;
            }
        }

        self::assertSame(
            [],
            $logic,
            'config/bundles.php has grown something that is not a declaration. Flex regenerates that file from '
            . 'its own template when a package is added, so whatever was just put there will be deleted by the '
            . "next `composer require` and nobody will be told. Put the rule in App\\Kernel and the datum in\n"
            . 'config/optional_bundles.php instead (XIV-111, §4.4).',
        );
    }

    /**
     * Every file under `config/`, with its comments removed, keyed by its path
     * relative to that directory.
     *
     * @return array<string, string>
     */
    private function configurationFiles(): array
    {
        $config = $this->configDirectory();

        $finder = (new Finder())
            ->files()
            ->in($config)
            ->name(['*.php', '*.yaml', '*.yml']);

        $files = [];

        foreach ($finder as $file) {
            $contents = $file->getContents();

            $files[$file->getRelativePathname()] = $file->getExtension() === 'php'
                ? $this->phpWithoutComments($contents)
                : $this->yamlWithoutComments($contents);
        }

        return $files;
    }

    /**
     * PHP source with every comment token dropped, using PHP's own lexer.
     *
     * A regular expression over `//` and `/* *\/` would be defeated by the first
     * comment containing a quote or the first string containing a slash, and
     * this file's whole job is to be trusted about what a configuration file
     * says.
     */
    private function phpWithoutComments(string $code): string
    {
        $kept = [];

        foreach (token_get_all($code) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }

                $kept[] = $token[1];

                continue;
            }

            $kept[] = $token;
        }

        return implode('', $kept);
    }

    /** YAML with its comment lines removed; see the class docblock for the caveat. */
    private function yamlWithoutComments(string $yaml): string
    {
        $lines = preg_split('/\R/', $yaml);
        \assert(\is_array($lines));

        $kept = array_filter($lines, static fn (string $line): bool => !preg_match('/^\s*#/', $line));

        return implode("\n", $kept);
    }

    private function configDirectory(): string
    {
        return \dirname(__DIR__, 3) . '/config';
    }
}

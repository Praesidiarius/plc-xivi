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

use App\Deployment\PlaceholderSecretGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the guard refuses, and — the half that is easier to get wrong — what it
 * lets through (XIV-61, docs/architecture/deployment.md §4.2).
 *
 * ## Two kinds of test here, and the second is the one that matters
 *
 * Most of what follows runs against a **fabricated `.env`** in a temporary
 * directory, because the rule under test is "live value equals committed value"
 * and that is a claim about a comparison rather than about any particular
 * string. Written against the real file, every one of these would start passing
 * or failing for reasons that have nothing to do with the guard the day somebody
 * edits `.env`.
 *
 * {@see testTheRealCommittedPlaceholdersAreRefused} is the exception and is the
 * regression test. It points the guard at this repository's own `.env` and
 * feeds it exactly what a freshly built production image contains, which is the
 * situation XIV-61 was opened about: `composer dump-env prod` compiles those
 * values into `.env.local.php`, a deployment that supplies no real environment
 * variable runs on them, and nothing anywhere says so. If the guard ever stops
 * recognising them — a quoting change, a renamed variable, a `.env` reformatted
 * — that test is what says so.
 *
 * ## Why `$_SERVER` is written to directly
 *
 * The guard reads the process environment because that is its subject: the
 * question is what this process actually received, and an injected value would
 * be a second answer to it. A test of that has to write to the same place, and
 * the environment is global, so each test restores what it found — the shape
 * {@see \App\Tests\Functional\Tenancy\TenantSecretRotationTest} already uses for
 * the same variable.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PlaceholderSecretGuardTest extends TestCase
{
    /** The fabricated placeholder, deliberately not the one `.env` carries. */
    private const string COMMITTED = 'committed-and-therefore-public';

    private const string KEYRING = '{"dev":"ZGV2LW9ubHkta2V5LWRvLW5vdC11c2UtaW4tcHJvZCE="}';

    /** @var array<string, mixed> */
    private array $server = [];

    private string $projectDir = '';

    protected function setUp(): void
    {
        foreach (PlaceholderSecretGuard::SECRETS as $name) {
            $this->server[$name] = $_SERVER[$name] ?? null;
        }

        // A directory of its own per test, holding nothing but the `.env` the
        // test wants to be committed. `sys_get_temp_dir()` rather than var/,
        // because var/ is an anonymous volume in this container and a leftover
        // file there outlives the run that made it.
        $this->projectDir = sys_get_temp_dir() . '/xivi-guard-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->server as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);

                continue;
            }

            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        if (is_file($this->projectDir . '/.env')) {
            unlink($this->projectDir . '/.env');
        }

        if (is_dir($this->projectDir)) {
            rmdir($this->projectDir);
        }
    }

    /**
     * The ordinary case for everybody working on this project, and the one that
     * would make the check unusable if it were wrong.
     *
     * `bin/ci`, the test suite and `bin/compose up` all run on the committed
     * placeholders on purpose — that is what lets a fresh checkout start with
     * nothing configured — so a guard that objected in development would be
     * objecting to the design. The environment decides rather than the debug
     * flag, for the reason App\Mail\NonProductionMailGuard gives: production is
     * something that can legitimately be run in debug while somebody diagnoses
     * it, and an instance being diagnosed is still an instance serving
     * customers.
     */
    #[DataProvider('nonProductionEnvironments')]
    public function testItStandsDownOutsideProduction(string $environment): void
    {
        $this->commit(['APP_SECRET' => self::COMMITTED]);
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = self::COMMITTED;

        self::assertSame([], $this->guard($environment)->refusals());
    }

    /** @return iterable<string, array{string}> */
    public static function nonProductionEnvironments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }

    public function testAValueEqualToTheCommittedOneIsRefused(): void
    {
        $this->commit(['APP_SECRET' => self::COMMITTED]);
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = self::COMMITTED;

        $refusals = $this->guard()->refusals();

        self::assertArrayHasKey('APP_SECRET', $refusals);
    }

    /**
     * The whole point of the feature: a deployment that supplied its own value
     * is fine, and a real environment variable is exactly how one does that.
     */
    public function testARealValueIsLetThrough(): void
    {
        $this->commit(['APP_SECRET' => self::COMMITTED]);
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'a2f0c1e9b7d4a6f8c0e2b4d6a8f0c2e4';

        self::assertSame([], $this->guard()->refusals());
    }

    /**
     * Unset is refused too, and it is not the same situation as a placeholder.
     *
     * In practice `.env.local.php` means production always has *something*, so
     * this is the case that only arises when somebody strips the compiled
     * environment — but "no secret at all" and "a public secret" both end with
     * an instance that must not serve, and only one of them would have been
     * caught by comparing against `.env`.
     */
    public function testAnEmptyOrUnsetValueIsRefused(): void
    {
        $this->commit(['APP_SECRET' => self::COMMITTED]);
        unset($_SERVER['APP_SECRET'], $_ENV['APP_SECRET']);

        self::assertArrayHasKey('APP_SECRET', $this->guard()->refusals());

        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = '   ';

        self::assertArrayHasKey('APP_SECRET', $this->guard()->refusals());
    }

    /**
     * The refusal has to be actionable on its own, because it is very often the
     * only thing an operator gets: a container that will not come up produces
     * one screen of output and no application to go and ask.
     *
     * So: which variable, that it is the committed one, and the command that
     * makes a real one.
     */
    public function testTheRefusalNamesTheVariableAndHowToSetIt(): void
    {
        $this->commit(['APP_SECRET' => self::COMMITTED]);
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = self::COMMITTED;

        $refusal = $this->guard()->refusals()['APP_SECRET'] ?? '';

        self::assertStringContainsString('APP_SECRET', $refusal);
        self::assertStringContainsString('placeholder committed in .env', $refusal);
        self::assertStringContainsString('random_bytes(32)', $refusal);
    }

    /**
     * Only enough of the value to recognise it.
     *
     * The placeholder is public, so printing it whole would leak nothing — but
     * this text lands in container logs and in whatever a deploy tool captures,
     * and a log line that *looks* like a secret being printed teaches everybody
     * who reads it that secrets get printed here.
     */
    public function testTheRefusalDoesNotEchoTheWholeValue(): void
    {
        $this->commit(['APP_SECRET' => self::COMMITTED]);
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = self::COMMITTED;

        self::assertStringNotContainsString(self::COMMITTED, $this->guard()->refusals()['APP_SECRET'] ?? '');
    }

    /**
     * Quoted values, which is the case a hand-rolled parser gets wrong.
     *
     * `TENANT_SECRET_KEYS` is single-quoted JSON containing double quotes in
     * `.env`. Splitting each line on `=` would compare a live value against one
     * that still has its quotes, which never matches — and the guard would then
     * pass silently, for ever, on the secret with the worst consequences. That
     * is why it goes through Symfony's own Dotenv parser.
     */
    public function testAQuotedCommittedValueIsStillRecognised(): void
    {
        $this->commit(['TENANT_SECRET_KEYS' => "'" . self::KEYRING . "'"]);
        $_SERVER['TENANT_SECRET_KEYS'] = $_ENV['TENANT_SECRET_KEYS'] = self::KEYRING;

        self::assertArrayHasKey('TENANT_SECRET_KEYS', $this->guard()->refusals());
    }

    /**
     * This repository's own `.env`, against what a freshly built production
     * image actually holds.
     *
     * The one test here that is allowed to know real values, and the reason is
     * that this *is* the bug: the Dockerfile's `composer dump-env prod` compiles
     * these two lines into `.env.local.php`, and a deployment that sets neither
     * variable runs on them looking perfectly healthy.
     */
    public function testTheRealCommittedPlaceholdersAreRefused(): void
    {
        $projectDir = \dirname(__DIR__, 3);

        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'dev-only-not-a-real-secret';
        $_SERVER['TENANT_SECRET_KEYS'] = $_ENV['TENANT_SECRET_KEYS'] = self::KEYRING;

        $refusals = (new PlaceholderSecretGuard('prod', $projectDir))->refusals();

        self::assertSame(
            ['APP_SECRET', 'TENANT_SECRET_KEYS'],
            array_keys($refusals),
            'The values committed in .env are no longer the ones this test knows about. If .env '
            . 'changed, change them here; if the guard stopped recognising them, that is the bug.',
        );
    }

    /**
     * A `.env` that cannot be read is a refusal rather than a pass.
     *
     * "Cannot tell whether this instance is running on a public secret" is not a
     * question to resolve in favour of starting, and the file ships in the
     * production image deliberately — `.dockerignore` excludes `.env.*` and
     * keeps `.env` — so its absence means something is wrong with the build
     * rather than with the deployment.
     */
    public function testAnUnreadableEnvFileRefusesRatherThanPasses(): void
    {
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'a2f0c1e9b7d4a6f8c0e2b4d6a8f0c2e4';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot read .*\.env/');

        $this->guard()->refusals();
    }

    /** @param array<string, string> $values */
    private function commit(array $values): void
    {
        $lines = '';
        foreach ($values as $name => $value) {
            $lines .= $name . '=' . $value . "\n";
        }

        file_put_contents($this->projectDir . '/.env', $lines);
    }

    private function guard(string $environment = PlaceholderSecretGuard::PRODUCTION): PlaceholderSecretGuard
    {
        return new PlaceholderSecretGuard($environment, $this->projectDir);
    }
}

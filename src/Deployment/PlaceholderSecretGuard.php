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

namespace App\Deployment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Whether this process is running on a secret that anybody can read out of the
 * repository (XIV-61, docs/architecture.md §4.2).
 *
 * ## The failure this exists for, which was demonstrated rather than imagined
 *
 * `.env` is committed and public, and it carries working values for everything
 * the application needs so that `git clone && bin/compose up` gets a running
 * instance with nothing configured. Two of those values are secrets:
 * `APP_SECRET` and `TENANT_SECRET_KEYS`. Both say in a comment beside them that
 * they are placeholders, and both are real, valid values that the application
 * accepts without complaint.
 *
 * The production image compiles `.env` into `.env.local.php` during the build
 * (`composer dump-env prod` in the Dockerfile). Inspected in a freshly built
 * image, that file contains:
 *
 *     'APP_SECRET' => 'dev-only-not-a-real-secret',
 *
 * A real environment variable still overrides it, so a deployment that supplies
 * `APP_SECRET` is fine. **A deployment that forgets is also fine, right up until
 * somebody notices.** There is no error, no warning and no degraded behaviour:
 * sessions work, cookies are signed, invitation links verify. The instance is
 * simply signing them with a value published on the internet, and the way that
 * surfaces is not a log line — it is somebody forging one.
 *
 * `TENANT_SECRET_KEYS` is the same shape and worse in consequence. Its dev
 * keyring is committed in `.env` a few lines further down, and it is what
 * encrypts every tenant's database password and every tenant's outgoing-mail
 * password at rest in the control-plane database (see
 * App\Tenancy\Security\TenantSecretCipher, and note what that class is honest
 * about: it defends against a *copy* of the control database, which is exactly
 * the threat a public key removes the defence against).
 *
 * ## Why "equal to what is committed in `.env`" is the rule
 *
 * The obvious implementation is a list of known-bad strings in this file. It was
 * not written that way, because that list has to be edited every time `.env`
 * changes and the day it is not edited is the day this class quietly stops
 * checking one of them — the failure AGENTS.md keeps describing, where a green
 * check means "not looked" rather than "looked and fine".
 *
 * The rule here needs nothing remembered, because it is a restatement of what
 * makes a value dangerous in the first place: **a secret whose live value is
 * byte-identical to the value committed in a public file is a published
 * secret.** `.env` is read from disk at the moment of the check — it ships in
 * the production image, deliberately, since Symfony reads it for defaults — and
 * whatever placeholder it holds today is what this compares against. Change the
 * placeholder in `.env` and the check follows it with no edit here.
 *
 * What still has to be listed is *which* variables are secrets, and that list is
 * short and stable (`SECRETS` below). Getting it wrong in the safe direction —
 * forgetting to add a third secret one day — leaves the two that matter checked,
 * where getting a list of literal values wrong leaves nothing checked at all.
 *
 * ## Why it reads `$_SERVER` rather than taking the values as arguments
 *
 * The question is "what did this process actually receive", and every layer of
 * indirection between the check and that question is a layer that can answer
 * about something else. `$_SERVER` is where Symfony's own runtime puts the
 * resolved environment — real variables first, then `.env.local.php`, then the
 * `.env` files — and it is what `%env(APP_SECRET)%` resolves out of. Injecting
 * `%env(APP_SECRET)%` would work today and would be one more place for the
 * answer to come from, so it is not done.
 *
 * ## Why this stands down outside production
 *
 * `bin/ci`, the test suite and `bin/compose up` all run on those placeholders on
 * purpose, and that is the ordinary case rather than an oversight — the whole
 * reason `.env` carries working values is that a fresh checkout should start.
 * Refusing there would be refusing development.
 *
 * The *environment* decides, not the debug flag, for the same reason
 * App\Mail\NonProductionMailGuard gives: the environment is what the kernel
 * actually allows (`Kernel::getAllowedEnvs()`: prod, dev, test), while debug is
 * something production can legitimately be run with while somebody diagnoses
 * a problem — and an instance being diagnosed is still an instance serving
 * customers.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PlaceholderSecretGuard
{
    /** The one environment in which a published secret is a refusal. */
    public const string PRODUCTION = 'prod';

    /**
     * The variables whose committed value is a secret rather than a default.
     *
     * Not every variable in `.env` belongs here, and most emphatically not
     * everything that looks like configuration: `DATABASE_URL` carries the
     * compose password and is expected to be replaced, but a deployment that
     * left it alone cannot connect to anything and finds out in seconds. These
     * two are the pair that *work* while being public, which is the property
     * that makes them dangerous and is the property this class is about.
     *
     * `TENANT_ADMIN_DSN` is deliberately absent for the same reason as
     * `DATABASE_URL`: it names a host that does not exist outside compose, so
     * forgetting it fails loudly at the first `tenant:provision`.
     */
    public const array SECRETS = ['APP_SECRET', 'TENANT_SECRET_KEYS'];

    /**
     * What each secret protects and how to make a real one, in the words an
     * operator reading a refused container start needs.
     *
     * Kept here rather than in the command because it is the part somebody has
     * to *act* on, and the command's job is only to decide where the words go —
     * a console, a log, or eventually a deploy tool's output.
     *
     * @var array<string, array{protects: string, generate: string}>
     */
    private const array ADVICE = [
        'APP_SECRET' => [
            'protects' => 'Symfony signs remember-me cookies, CSRF tokens and signed URLs with it — '
                . 'including the sign-in links tenant:provision and signup:provision send by mail '
                . '(XIV-1). A published value means every one of those can be forged by anybody who '
                . 'has read this repository.',
            'generate' => "php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'",
        ],
        'TENANT_SECRET_KEYS' => [
            'protects' => "It encrypts every tenant's database password and every tenant's "
                . 'outgoing-mail password at rest in the control-plane database '
                . '(App\\Tenancy\\Security\\TenantSecretCipher). A published keyring means a copy of '
                . "that one database decrypts to every customer's credentials.",
            'generate' => "php -r 'echo json_encode([\"k1\" => base64_encode(random_bytes(32))]), PHP_EOL;'"
                . "\n…and point TENANT_SECRET_KEY_ID at the id you chose.",
        ],
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * Whether this instance may serve, and what to do about it if not.
     *
     * An empty list is the pass. Everything else is a paragraph per offending
     * variable, already addressed to a person: the caller decides where to print
     * it and nothing here assumes a console.
     *
     * @return array<string, string> the offending variable names, each mapped to
     *                               what is wrong and what to set
     */
    public function refusals(): array
    {
        if ($this->environment !== self::PRODUCTION) {
            return [];
        }

        $committed = $this->committedValues();
        $refusals = [];

        foreach (self::SECRETS as $name) {
            // `$_SERVER` first and `$_ENV` second, which is the order Symfony's
            // own env-var processor uses. In practice under FrankenPHP both are
            // populated identically; reading only one of them would work by
            // accident under one SAPI and not under the next.
            $live = $_SERVER[$name] ?? $_ENV[$name] ?? null;
            $live = \is_string($live) ? $live : null;

            if ($live === null || trim($live) === '') {
                $refusals[$name] = $this->missing($name);

                continue;
            }

            // The whole rule, in one comparison. `$committed` may legitimately
            // not contain the name — somebody could add a secret to SECRETS
            // before adding a default to `.env` — and a variable with no
            // committed counterpart simply cannot be the committed value.
            if (isset($committed[$name]) && hash_equals($committed[$name], $live)) {
                $refusals[$name] = $this->published($name, $committed[$name]);
            }
        }

        return $refusals;
    }

    /**
     * What `.env` commits, parsed the way Symfony parses it.
     *
     * `Dotenv::parse()` rather than reading lines and splitting on `=`, because
     * the values here are quoted in three different styles — `TENANT_SECRET_KEYS`
     * is single-quoted JSON containing double quotes — and a comparison against
     * a value whose quotes were not stripped is a comparison that never matches.
     * That is the failure mode worth designing against: this check going quiet
     * rather than going loud.
     *
     * **Unreadable `.env` is a refusal, not a pass.** The file is copied into the
     * production image on purpose and is the only thing this class can compare
     * against; without it the honest answer is "cannot tell", and "cannot tell"
     * about whether an instance is running on a public secret is not something to
     * resolve in favour of starting.
     *
     * @return array<string, string>
     */
    private function committedValues(): array
    {
        $path = $this->projectDir . '/.env';
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if (!\is_string($contents)) {
            throw new \RuntimeException(sprintf(
                'Cannot read %s, so there is no way to tell whether this instance is running on the '
                . 'placeholder secrets committed in it. That file ships in the production image '
                . 'deliberately (see .dockerignore, which excludes .env.* and keeps .env); a build '
                . 'without it is a build this check cannot vouch for.',
                $path,
            ));
        }

        $parsed = (new Dotenv())->parse($contents, $path);

        // Dotenv is typed as returning mixed values because it will happily parse
        // a file into anything; every value in ours is a string, and PHPStan is
        // entitled to be told so rather than trusted to guess.
        $values = [];
        foreach ($parsed as $name => $value) {
            if (\is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    private function published(string $name, string $placeholder): string
    {
        return sprintf(
            "%s is still the placeholder committed in .env.\n\n%s\n\n%s\n\n%s\n\n%s",
            $name,
            $this->paragraph(sprintf(
                'Its value here is %s, which is in this repository and is therefore public.',
                $this->redact($placeholder),
            )),
            $this->paragraph(self::ADVICE[$name]['protects'] ?? 'It is a secret, and it is public.'),
            $this->paragraph(sprintf(
                'Set a real one, as a real environment variable or through the secrets vault '
                . '(bin/console secrets:set %s). A real variable wins over the .env.local.php the '
                . 'image build compiled, so nothing has to be rebuilt.',
                $name,
            )),
            $this->indent(self::ADVICE[$name]['generate'] ?? 'Generate one with anything but this file.'),
        );
    }

    private function missing(string $name): string
    {
        return sprintf(
            "%s is empty or unset.\n\n%s\n\n%s\n\n%s",
            $name,
            $this->paragraph(self::ADVICE[$name]['protects'] ?? 'It is a secret, and this instance has none.'),
            $this->paragraph(sprintf(
                'Set it as a real environment variable or through the secrets vault '
                . '(bin/console secrets:set %s).',
                $name,
            )),
            $this->indent(self::ADVICE[$name]['generate'] ?? 'Generate one with anything but this file.'),
        );
    }

    /**
     * Prose, wrapped and indented so a terminal does not decide where the lines
     * break.
     *
     * Worth the four lines: this text is read exactly once, by somebody looking
     * at a container that will not start, and half of them will be reading it
     * through `docker logs` in a window that is not eighty columns wide. A
     * paragraph that has already decided where it breaks survives that; one that
     * has not turns into a wall.
     */
    private function paragraph(string $text): string
    {
        return '  ' . wordwrap($text, 74, "\n  ");
    }

    /** A command line, offset so it is obviously something to type. */
    private function indent(string $text): string
    {
        return '      ' . str_replace("\n", "\n      ", $text);
    }

    /**
     * Enough of the placeholder to recognise it, and not the whole of it.
     *
     * The value is public, so printing it in full would leak nothing — but this
     * message ends up in container logs, in CI output and in whatever a deploy
     * tool captures, and a log line that *looks* like a secret being printed
     * teaches everybody who reads it that secrets get printed here. The point is
     * to let an operator confirm which value the process actually has, and
     * fifteen characters does that.
     */
    private function redact(string $value): string
    {
        if (mb_strlen($value) <= 15) {
            return sprintf('"%s"', $value);
        }

        return sprintf('"%s…"', mb_substr($value, 0, 15));
    }
}

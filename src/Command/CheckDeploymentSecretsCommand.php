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

namespace App\Command;

use App\Deployment\PlaceholderSecretGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The refusal, and the place a container start asks for it (XIV-61,
 * docs/architecture/deployment.md §4.2).
 *
 * ## Why a console command called from the entrypoint, and not something earlier
 *
 * The obvious homes for this check are all earlier than this one and all wrong,
 * in the same way, which is worth writing down because each of them is the first
 * thing anybody proposes.
 *
 * **Not a compiler pass, and not `Kernel::boot()`.** Both would refuse the
 * *image build* as well as the deployment. The Dockerfile runs
 * `composer dump-env prod` and then `composer run-script post-install-cmd`,
 * which is `cache:clear` — so the production kernel is booted, in the production
 * environment, on the placeholder secrets, as a normal part of building an
 * image. It has to be: nobody supplies a customer's `APP_SECRET` to a build, and
 * the same image is the one every deployment runs. A check in the container or
 * in `boot()` would make the image unbuildable, which is a fine way to have the
 * check deleted within a day.
 *
 * **Not a `kernel.request` listener.** That is a container which starts, reports
 * healthy, binds its port and then answers every request with a 500 — an outage
 * dressed as a running service. The failure this guards against is a deployment
 * that *looks perfectly healthy*, and answering it with a different kind of
 * looking-healthy is no answer at all.
 *
 * **Not only a deploy script.** A deploy can be skipped, replayed from an older
 * revision, or bypassed entirely by somebody restarting a container by hand — and
 * the container that comes back from any of those routes is the one serving
 * customers. A container that refuses to start cannot be bypassed by not running
 * something.
 *
 * So: the entrypoint runs this before the database wait and before any
 * migration, and `set -e` turns a non-zero exit here into a container that never
 * reaches `frankenphp run`. The orchestrator sees a process that exited with the
 * reason on its stdout, rather than a healthy instance signing cookies with a
 * value published on GitHub.
 *
 * ## What it costs the ordinary case
 *
 * Nothing, twice over. Outside production the guard returns immediately without
 * reading anything (dev, test and `bin/ci` all run on the placeholders on
 * purpose), and in production it is one small file read and two string
 * comparisons on a path that already pays for a kernel boot.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'deploy:check-secrets',
    description: 'Refuse to run in production on a secret that is committed in this repository',
)]
final readonly class CheckDeploymentSecretsCommand
{
    public function __construct(private PlaceholderSecretGuard $guard)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $refusals = $this->guard->refusals();

        if ($refusals === []) {
            // Deliberately quiet on the happy path and deliberately not silent.
            // This runs on every container start, so a line per start is a line
            // somebody will eventually read while working out why a container is
            // looping — and "the secrets were checked and were fine" is exactly
            // what they need to know at that moment in order to stop looking here.
            $io->writeln('<info>Secrets:</info> no placeholder values in use.');

            return Command::SUCCESS;
        }

        // `getErrorStyle()` so the reason survives being piped: a container that
        // refuses to start has one chance to say why, and half the tools that
        // capture that output capture only one of the two streams.
        $error = $io->getErrorStyle();

        $error->error(sprintf(
            '%s in this environment %s a placeholder committed in .env.',
            implode(' and ', array_keys($refusals)),
            \count($refusals) === 1 ? 'is' : 'are',
        ));

        foreach ($refusals as $refusal) {
            $error->writeln($refusal);
            $error->newLine();
        }

        $error->writeln(
            'Refusing to start. This check runs only at APP_ENV=prod — development, the test'
            . "\nsuite and bin/ci run on these placeholders on purpose, and are unaffected."
            . "\n\nSee https://praesidiarius.github.io/plc-xivi-docs/running/configuration/"
            . "\n(\"Before deploying anywhere real\"), and docs/architecture/deployment.md §4.2.",
        );

        return Command::FAILURE;
    }
}

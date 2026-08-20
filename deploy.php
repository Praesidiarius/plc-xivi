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

namespace Deployer;

/**
 * **Deployer is used here as an SSH task runner, and its release layout is
 * deliberately unused** (XIV-61, docs/architecture/deployment.md §4.8).
 *
 * Read this before changing anything below, because the setup looks broken to
 * anybody who knows Deployer and is not.
 *
 * Deployer's model is `releases/N`, a `shared/` directory, and a `current`
 * symlink that gets moved, on a host that has PHP and Composer installed. This
 * application does not deploy that way. It ships as a container image built from
 * the Dockerfile's `frankenphp_prod` target, and the target host has Docker and
 * nothing else: no PHP, no Composer, no vendor directory to link. There is no
 * `release_path` here, `deploy:prepare` and `deploy:symlink` are never called,
 * and `dep rollback` is not registered.
 *
 * So the release machinery is not disabled because somebody forgot to configure
 * it. **Adopting it would mean abandoning the production image**, which is how
 * this application is built, tested and proven in CI. If a later change makes
 * that look tempting, the thing to change is the choice of tool, not this file.
 *
 * What Deployer is doing for us is the part it is genuinely good at: connecting
 * to one or more hosts, running commands in order, failing loudly, and letting a
 * human on a laptop drive it. That last part is the reason it beat a GitHub
 * Actions job, which was the other candidate. XIV-60 wants the control instance
 * to be **not publicly reachable**, and a hosted runner cannot reach a box that
 * is not on the internet while an operator on the VPN can. One tool that deploys
 * both instances is worth more than a runner that can only ever deploy the
 * public half.
 *
 * ## The order, and why it is this order
 *
 *   1. Build the image locally and push it to ghcr.io.
 *   2. Pull it on the target, by digest.
 *   3. Run `bin/deploy` out of the new image: secrets, control plane, tenants.
 *   4. Replace the serving containers.
 *
 * **Three before four is the whole point.** The migration walk is additive only
 * (§4.2), so the old code keeps serving correctly against the new schema while
 * step three runs, and the instance stays up. Doing it the other way round would
 * put new code in front of customers whose databases had not moved yet.
 *
 * **Two before three** because `bin/deploy` runs out of the image being
 * deployed, so that the migration step can never be a version behind the
 * migrations it is applying.
 *
 * ## What rollback means here, and what it cannot undo
 *
 * `dep rollback` is not registered, and that is an answer rather than an
 * omission. Rolling back is `deploy:to --tag=<the previous digest>`, which
 * replaces the containers with the old image and takes a few seconds.
 *
 * **The databases do not come back with it.** A release that added a column has
 * added it to the control plane and to every tenant, and stepping the code back
 * leaves those columns in place. That is survivable precisely because the window
 * rule forbids a tenant migration from dropping or renaming anything: old code
 * meeting a newer additive schema finds every column it knew about, still there,
 * and ignores the ones it does not. It is not survivable if somebody works
 * around that rule, which is what `TenantMigrationsAreAdditiveTest` is for.
 *
 * So: **code rolls back, schema does not, and the schema is built so that it
 * does not have to.** A migration that genuinely has to remove something is two
 * releases, and the second one is not rollback-safe until the first is
 * everywhere.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */

/*
 * **`recipe/common.php` is deliberately not required**, which is the same
 * decision as the one above stated in code. Requiring it registers `rollback`,
 * `deploy:prepare`, `deploy:symlink`, `provision:mysql`, `logs:php-fpm` and
 * thirty more tasks belonging to the release layout and the PHP-on-the-host
 * model this deployment does not use. Every one of them would appear in
 * `dep list` as though it were something an operator here could run, and
 * `rollback` in particular would appear to work while doing nothing of the kind.
 *
 * Nothing below needs it: `task()`, `run()`, `runLocally()`, `test()`, `set()`
 * and `fail()` are Deployer's own functions rather than that recipe's tasks.
 */

set('application', 'xivi');

// **Where the deployment's own files live on the target.** Compose files and the
// env file holding the secrets, and nothing else. No checkout, no vendor, no
// releases.
set('deploy_path', '/opt/xivi');

// **The env file is never uploaded by a deploy**, deliberately. It holds
// APP_SECRET and the tenant keyring, and a deploy that shipped them every time
// would put the production secrets in the shell history of every machine that
// ever deployed, and would silently overwrite a value somebody rotated on the
// box. `secrets:install` is the one task that writes it, and it is run on
// purpose. See §4.8.
set('env_file', '{{deploy_path}}/.env.deploy');

/*
 * **Where SSH keeps its multiplexed connection socket**, and it is here because
 * the machine running a deploy is the dev container (§4.8).
 *
 * Deployer reuses one SSH connection for every command in a deploy, which is
 * worth keeping: a deploy is a few dozen commands and a fresh handshake for each
 * would be the slowest part of it. The socket goes in `~/.ssh` by default, and
 * `~` resolves out of `/etc/passwd` rather than from `HOME`, so inside this
 * container it points at a directory that does not exist and every host fails
 * with `unix_listener: cannot bind to path`.
 *
 * `/tmp` is writable whatever uid the container was told to run as, and a unix
 * socket path has about a hundred characters before the kernel refuses it, which
 * a home directory plus a hostname plus a user can exceed on its own.
 */
set('ssh_control_path', '/tmp/dep-%C');

// **Absolute, because `-f` is resolved against the working directory and not
// against `--project-directory`.** An SSH session lands in the remote user's
// home, so relative names sent Compose looking for /root/compose.yaml.
set('compose_files', '-f {{deploy_path}}/compose.yaml -f {{deploy_path}}/compose.prod.yaml');
set('compose', 'docker compose --project-directory {{deploy_path}} {{compose_files}} --env-file {{env_file}}');

// ghcr.io, same account as the repository (XIV-61). Free for private images,
// and CI already holds credentials for it, so it adds no service to run, pay
// for or back up.
set('registry', 'ghcr.io');
set('image_name', getenv('XIVI_IMAGE_NAME') ?: 'ghcr.io/praesidiarius/xivi');

/*
 * **Two targets, from two build targets, and this file is written for both**
 * (XIV-96, §4.4). The public instance runs `frankenphp_public`, which is built
 * without the administration surface. The internal one runs `frankenphp_prod`,
 * which has it, and XIV-60 requires that it not be publicly reachable.
 *
 * Neither host is described here beyond its role. Hostnames, users and ports
 * come from `.hosts.yaml`, which is gitignored, because this ticket also decided
 * that the deploy definition must not bake in a provider: the German test box is
 * a rehearsal for a move to a Swiss one, and a move that requires editing a
 * committed file is a move somebody will do wrong under pressure.
 */
if (file_exists(__DIR__.'/.hosts.yaml')) {
    import(__DIR__.'/.hosts.yaml');
}

// **Which of the two images this host runs.** Overridden per host in
// `.hosts.yaml`; the public build is the default because it is the one that
// faces customers, and a host that is not told which it is should get the one
// without the administration surface rather than the one with it.
set('docker_target', 'frankenphp_public');

// **What to call the image being built.** The version rather than `latest`, so
// that the registry reads as a history and a rollback has something to name.
// `--tag=` on the command line wins, which is how a hotfix that is not a release
// gets deployed.
option('tag', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The image tag or digest to build or deploy');

set('image_tag', fn () => input()->getOption('tag') ?: trim(runLocally('git describe --tags --always --dirty')));

/*
 * **The exact image a task will pull and run.** `image:push` overwrites this
 * with the digest the registry gave back, but `deploy:to` never builds anything,
 * so it needs the reference to be derivable from `--tag` alone. Without that,
 * deploying an image that already exists (which is what a rollback is) failed on
 * an unset variable.
 *
 * A digest is joined with `@` and a tag with `:`, which is the difference
 * between naming one immutable image and naming whatever the tag points at
 * today. Rollbacks should always be given a digest.
 */
set('image_ref', function () {
    $tag = get('image_tag');
    $separator = str_starts_with($tag, 'sha256:') ? '@' : ':';

    return get('image_name').$separator.$tag;
});

desc('Build the production image and push it to the registry, by digest');
task('image:push', function (): void {
    $tag = get('image_tag');
    $target = get('docker_target');
    $image = get('image_name');

    // Built locally rather than on the target. Putting the build toolchain on
    // the box makes builds compete with the running application for the RAM the
    // sizing was done against, and XIV-99 showed the other cost the day this was
    // decided: a GitHub outage would then block a deploy rather than merely a
    // build.
    runLocally("docker build --target $target --tag $image:$tag .", timeout: 1800);
    runLocally("docker push $image:$tag", timeout: 1800);

    // **The digest, not the tag, is what the target runs.** A tag can be moved,
    // and a container that restarts three weeks later would come back as
    // whatever it points at by then. Resolving it here means the whole deploy,
    // including a rollback to it later, names one immutable image.
    $digest = trim(runLocally("docker inspect --format='{{index .RepoDigests 0}}' $image:$tag"));
    set('image_ref', $digest);

    writeln("<info>Deploying</info> $digest");
});

desc('Log the target in to the registry');
task('registry:login', function (): void {
    $token = getenv('GHCR_TOKEN');
    $user = getenv('GHCR_USER');

    if ($token === false || $token === '' || $user === false || $user === '') {
        throw new \RuntimeException(
            'GHCR_USER and GHCR_TOKEN must be in the environment of the machine running the deploy. '
            .'They are not stored on the target and not committed here. See §4.8.'
        );
    }

    // **Through an environment variable into stdin, not as an argument.** A
    // token on the command line is in the target's process list for as long as
    // the command runs, and in the shell history of anybody who copies the line
    // out of a log. `run()` has no stdin parameter, so the variable is the way
    // to get it there.
    run(
        'echo "$GHCR_TOKEN" | docker login '.get('registry').' --username '.escapeshellarg($user).' --password-stdin',
        env: ['GHCR_TOKEN' => $token],
        secrets: ['GHCR_TOKEN' => $token],
    );
});

/**
 * Write a local file to the target over the SSH connection already open.
 *
 * **Deployer's own `upload()` shells out to rsync, on both ends**, and the
 * target's documented requirement is Docker and Compose and nothing else. Debian
 * 13 happens to ship rsync, so `upload()` would work today and the documentation
 * would be quietly false: the next target, or a slimmer base image, would fail a
 * deploy on a dependency nobody wrote down. Base64 through `cat` needs only the
 * shell, which is not a dependency so much as the thing an SSH session already
 * is.
 *
 * These are configuration files of a few kilobytes. This would be the wrong way
 * to move an image.
 */
function put(string $localPath, string $remotePath): void
{
    $contents = file_get_contents($localPath);

    if ($contents === false) {
        throw new \RuntimeException("Cannot read $localPath.");
    }

    run(sprintf('echo %s | base64 -d > %s', escapeshellarg(base64_encode($contents)), escapeshellarg($remotePath)));
}

desc('Write the deployment env file on the target (run deliberately, not per deploy)');
task('secrets:install', function (): void {
    $alias = get('alias');
    $envFile = get('env_file');
    $local = __DIR__.'/.env.deploy.'.$alias;

    if (!file_exists($local)) {
        throw new \RuntimeException(
            "No local .env.deploy.$alias to install. Copy .env.deploy.dist to it, fill it in, and keep it out of git."
        );
    }

    run('mkdir -p {{deploy_path}}');
    put($local, $envFile);
    // Before anything is written into it would be better, but the file arrives
    // in one command. 600 immediately after is the narrowest window available
    // without a temporary file somewhere else on the same disk.
    run('chmod 600 '.escapeshellarg($envFile));

    writeln("<info>Installed</info> $envFile. Rotating a secret means editing it there and deploying again.");
});

desc('Copy the compose files the target runs');
task('deploy:compose_files', function (): void {
    run('mkdir -p {{deploy_path}}');
    put(__DIR__.'/compose.yaml', get('deploy_path').'/compose.yaml');
    put(__DIR__.'/compose.prod.yaml', get('deploy_path').'/compose.prod.yaml');
});

desc('Refuse to deploy to a target that has no secrets installed');
task('deploy:check_secrets_present', function (): void {
    // parse() is what expands {{...}}; a thrown string is not run through it, so
    // the first version of this told an operator to look for a file literally
    // called {{env_file}}.
    if (!test('[ -f {{env_file}} ]')) {
        throw new \RuntimeException(sprintf(
            'No %s on this target. Run `dep secrets:install %s` once before the first deploy.',
            get('env_file'),
            get('alias'),
        ));
    }
});

desc('Record which image this target runs');
task('deploy:record_image', function (): void {
    /*
     * **Written into the env file rather than passed on each command**, because
     * the target has to work when the deploy is not there. `XIVI_IMAGE` has no
     * default in `compose.prod.yaml` on purpose, so without this every
     * `docker compose ps`, `logs` or `restart` an operator types on the box fails
     * with `required variable XIVI_IMAGE is missing a value`, and a reboot brings
     * nothing back up. Recording it also means the box itself is the answer to
     * "what is actually deployed here".
     *
     * Rewritten through a temporary file in the same directory so an interrupted
     * write cannot leave the deployment without its secrets.
     */
    $envFile = get('env_file');
    $line = 'XIVI_IMAGE='.get('image_ref');

    run(sprintf(
        'grep -v "^XIVI_IMAGE=" %1$s > %1$s.new || true; echo %2$s >> %1$s.new; chmod 600 %1$s.new; mv %1$s.new %1$s',
        escapeshellarg($envFile),
        escapeshellarg($line),
    ));
});

desc('Pull the image being deployed');
task('deploy:pull', function (): void {
    run('{{compose}} pull', timeout: 1800);
});

desc('Migrate: secrets, then the control plane, then every tenant');
task('deploy:migrate', function (): void {
    /*
     * **Out of the new image, before the serving containers are replaced.**
     * `bin/deploy` is the file to read for what this does and why it is not in
     * the container entrypoint. Its exit codes are the reason this is its own
     * step:
     *
     *   0  everything current
     *   1  the run could not happen at all
     *   3  some tenants failed and the rest are fine
     *
     * Deployer fails the deploy on any non-zero, which is what makes "migrated
     * 49 of 50" stop the release instead of reporting success. The failure names
     * the tenants and prints the retry line.
     *
     * **`compose run` rather than `docker run`**, and the difference is not
     * cosmetic. The first attempt at this plumbed the network and the env file by
     * hand and died on a fresh box with `network xivi_default not found`, because
     * nothing had created it yet: the stack does not exist until `deploy:up`, and
     * that runs after this on purpose. `compose run` starts the service's
     * dependencies first, so the database is up and healthy whether this is the
     * fiftieth deploy or the first, and the container it makes is on the right
     * network with the right environment without any of it being restated here.
     */
    run('{{compose}} run --rm php bin/deploy', timeout: 3600);
});

desc('Replace the serving containers');
task('deploy:up', function (): void {
    run('{{compose}} up -d --remove-orphans', timeout: 1800);
});

desc('Prove the instance is actually serving');
task('deploy:verify', function (): void {
    /*
     * Waits for the healthcheck, which asks the TLS ask endpoint about a hostname
     * this instance serves and therefore covers the control-plane database and
     * the registry query. A deploy that finished with the containers up but the
     * database unreachable would otherwise report success.
     *
     * **`--format json` rather than a Go template**, because Deployer expands
     * `{{...}}` in a command string before it is ever sent: asking Docker for
     * `{{.Service}}` made Deployer look for a config option called `.Service` and
     * fail the deploy after everything had already been replaced. Any Docker
     * format string with braces in it has this problem.
     */
    for ($i = 0; $i < 40; ++$i) {
        $lines = array_filter(explode("\n", run('{{compose}} ps --format json --all')));

        foreach ($lines as $line) {
            $row = json_decode(trim($line), true);

            if (!is_array($row) || ($row['Service'] ?? null) !== 'php') {
                continue;
            }

            $health = $row['Health'] ?? '';

            if ($health === 'healthy') {
                writeln('<info>Serving</info> on {{alias}}, and the registry answered.');

                return;
            }

            // A container that has exited is not going to become healthy, so say
            // so now rather than after another two minutes of polling.
            if (($row['State'] ?? '') === 'exited') {
                break 2;
            }
        }

        sleep(3);
    }

    run('{{compose}} logs --tail=50 php', nothrow: true);

    throw new \RuntimeException(
        'The php container never became healthy. Logs above. The previous image is still in the registry, '
        .'so `dep deploy:to '.get('alias').' --tag=<previous digest>` puts it back.'
    );
});

desc('Install the cron entries this build needs');
task('deploy:cron', function (): void {
    /*
     * **Generated out of the image being deployed, never from a file in the
     * repository** (XIV-61, §4.5, §4.8).
     *
     * The jobs are `App\Monitoring\ScheduledJobs`, in code, each carrying its
     * cadence and the sentence saying what is wrong while it is not running.
     * `deploy:crontab` prints them, so what lands on the target is what *this
     * release* needs rather than what a runbook remembers. A committed
     * `.cron-table` would be a second list, and the way you would find out it had
     * drifted is a job that quietly stopped being scheduled.
     *
     * **Run from the new image, before the containers are replaced**, which is
     * the same argument `bin/deploy` makes about itself: a schedule that is one
     * release behind its commands is a cron entry naming something that no longer
     * exists.
     *
     * `--wrapper` is not decoration. The host has Docker and no PHP, so the
     * printed `cd /app && bin/console` would fail every five minutes into mail
     * nobody reads. `--user root` is what `/etc/cron.d` requires and what a user
     * crontab refuses.
     */
    /*
     * **`--entrypoint php`, because the entrypoint talks.** It prints the
     * Symfony banner, the secrets check, the cache clear, the database wait and
     * "PHP app ready!", all to stdout, and the first version of this task wrote
     * every one of those lines into `/etc/cron.d/xivi` above the real entries.
     * Cron does not ignore what it cannot parse; a malformed file is a cron that
     * complains once a minute or stops reading. None of what the entrypoint does
     * is wanted here anyway: this prints a list and touches no database.
     */
    $crontab = run(sprintf(
        '{{compose}} run --rm --no-deps --entrypoint php php bin/console deploy:crontab --user=root --wrapper=%s',
        escapeshellarg(get('compose').' run --rm php bin/console'),
    ), nothrow: true);

    /*
     * **Refuse to write something that is not a crontab.** Writing junk into
     * `/etc/cron.d` is worse than not writing at all: it is the one directory on
     * the box where a bad file costs somebody else's scheduled jobs too. Every
     * line has to be blank, a comment, or an entry starting with a schedule
     * field, which is what the entrypoint's chatter fails.
     */
    $offending = array_values(array_filter(
        explode("\n", $crontab),
        static fn (string $line): bool => trim($line) !== ''
            && !str_starts_with(ltrim($line), '#')
            && preg_match('/^[\d*]\S*(\s+\S+){4}\s/', $line) !== 1,
    ));

    if ($offending !== []) {
        throw new \RuntimeException(sprintf(
            "deploy:crontab printed %d line(s) that are not cron entries, so nothing was installed. First: %s",
            \count($offending),
            trim($offending[0]),
        ));
    }

    /*
     * **Exit 3 is a real answer here and is not a failure to install.** The
     * command exits 3 when some jobs are watched by a monitoring service and
     * others are not, which is the state worth noticing (§4.5) and not a reason
     * to refuse a release. `nothrow` above, and the inconsistency is repeated
     * where the operator will see it.
     */
    if (str_contains($crontab, 'NOT WATCHED') && str_contains($crontab, '# Watched:')) {
        writeln('<comment>Some scheduled jobs are watched and some are not. See XIVI_MONITOR_PINGS.</comment>');
    }

    // Cron ignores a file in /etc/cron.d whose name has a dot in it, and refuses
    // one that is group or world writable. Both failures are silent.
    $path = '/etc/cron.d/xivi';

    /*
     * **The trailing newline is not tidiness.** Cron refuses a file in
     * `/etc/cron.d` that does not end in one, with `Missing newline before EOF,
     * this crontab file will be ignored`, and it refuses the *whole file*. That
     * is a total failure that looks like a complete success from every angle
     * except the one that matters: the file is present, its permissions are
     * right, every line in it is a valid entry, and running any of those lines
     * by hand works. `run()` returns the command's output trimmed, so the
     * newline has to be put back here.
     */
    run(sprintf('echo %s | base64 -d > %s', escapeshellarg(base64_encode($crontab."\n")), $path));
    run('chown root:root '.$path);
    run('chmod 644 '.$path);

    // Entries rather than lines: the file is mostly comments, and the first
    // version of this counted them and reported seventeen scheduled jobs where
    // there are five.
    $count = (int) run('grep -cE "^[0-9*]" '.$path.' || true');

    writeln(sprintf('<info>%d scheduled job(s)</info> installed in %s.', $count, $path));
});

desc('Remove images no longer referenced, so a 38 GB disk stays a 38 GB disk');
task('deploy:prune', function (): void {
    run('docker image prune -f');
});

desc('Build, push, migrate and release');
task('deploy', [
    'deploy:check_secrets_present',
    'image:push',
    'registry:login',
    'deploy:compose_files',
    'deploy:record_image',
    'deploy:pull',
    'deploy:migrate',
    'deploy:up',
    'deploy:verify',
    'deploy:cron',
    'deploy:prune',
]);

/*
 * Deploy an image that already exists, by digest or tag, without building.
 * This is what a rollback is: `dep deploy:to public --tag=sha256:...`.
 */
desc('Deploy an image that is already in the registry (this is what rollback is)');
task('deploy:to', [
    'deploy:check_secrets_present',
    'registry:login',
    'deploy:compose_files',
    'deploy:record_image',
    'deploy:pull',
    'deploy:migrate',
    'deploy:up',
    'deploy:verify',
    'deploy:cron',
]);

// A failed deploy leaves the previous containers running, because nothing is
// replaced until `deploy:up`, and that runs after the migration. So there is
// nothing to unwind: the instance is still serving the old image.
fail('deploy', 'deploy:failed');

desc('Say what a failed deploy left behind');
task('deploy:failed', function (): void {
    writeln('<comment>The deploy stopped before it replaced anything, so the previous image is still serving.</comment>');
    writeln('<comment>If it stopped during deploy:migrate, some tenants may have moved. The schema is additive, so the old code still runs against them.</comment>');
});

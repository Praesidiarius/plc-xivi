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

namespace Xivi\ControlPlane\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Entity\SignupStatus;
use Xivi\ControlPlane\Provisioning\SignupProvisioner;
use Xivi\ControlPlane\Provisioning\SignupProvisioningOutcome;
use Xivi\ControlPlane\Repository\SignupRequestRepository;

/**
 * Walks the confirmed signups and makes customers out of them (XIV-98).
 *
 * [XIV-64]'s endpoint records a signup and provisions nothing, on purpose: it is
 * anonymous, it is reachable from the open internet, and the thing that creates
 * a customer holds `TENANT_ADMIN_DSN`. **This is the privileged half**, and it
 * runs here — on the non-public side, from a console, where the credential
 * belongs. When [XIV-96] separates the two deployments this command goes in the
 * internal image.
 *
 * ## A command and cron, not a queue — for the third time
 *
 * The obvious shape is a message dispatched when somebody clicks their
 * confirmation link, consumed by a worker. There is no worker. This runtime is
 * FrankenPHP in classic mode with no worker block on purpose (§9.2), so nothing
 * in this deployment runs between requests and a queue with nothing draining it
 * is strictly worse than no queue at all: the customer's tenant is simply never
 * made, and the failure is silence.
 *
 * That is the same constraint, reached from the same direction, that settled
 * synchronous sending in [XIV-37] and periodic collection in [XIV-59]. It is not
 * repeated here as a rule of thumb — the point is that the constraint is about
 * the *runtime* rather than about any of the three features, so it produces the
 * same answer every time until somebody introduces a consumer process for a
 * reason of its own. On that day, moving this onto it is a small change.
 * Inventing one for a job that takes seconds and that nobody is sitting waiting
 * on is not.
 *
 * The cost is latency: somebody who confirms at ten past two is provisioned
 * whenever the cron next fires. That is a real cost and it is the deployment's
 * to choose — nothing here assumes a cadence, so an installation that runs this
 * every five minutes and one that runs it nightly both behave correctly.
 *
 * ## One failure must not cost the others
 *
 * {@see CollectTenantUsageCommand} is the shape, and its rule is this one's:
 * a run that stopped at the first customer whose mail server was refusing
 * connections would leave everybody behind them in the queue waiting another
 * whole cycle for a reason that has nothing to do with them. So the provisioner
 * returns an outcome rather than throwing, the failure is written onto that
 * signup's row, and the loop moves on.
 *
 * **It still exits non-zero when anything failed**, which under cron is how a
 * person finds out at all: the mail lands, it names the addresses, and the rest
 * of the queue was served anyway. A run that swallowed the failure would be
 * quieter and worse.
 *
 * ## Two places where this deliberately differs from `tenant:usage:collect`
 *
 * **An empty queue is a success, not an error.** That command errors when there
 * are no tenants, because an installation with no customers is a misconfigured
 * one. Here, no confirmed signups is the *ordinary* state of a healthy
 * installation between customers — most nights, most installations — and a cron
 * entry that mails somebody every night for being idle is a cron entry whose
 * mail nobody reads within a fortnight.
 *
 * **Nothing is ever given up on.** There is no attempt limit and no dead-letter
 * state. Every failure a retry could fix is one an operator fixes somewhere else
 * — a full disk, a mail server, a grant on the provisioning role — and if this
 * had disarmed itself in the meantime, the repair would be a two-step job whose
 * second step nobody remembers. The one failure retrying can never fix is
 * `preflight`, and that one repeats in every report until a person acts, which
 * is the pressure it should exert. `signup_request.provisioning_attempts` is
 * what makes the difference legible: one attempt is a bad afternoon, two hundred
 * is a name somebody has to give back.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'signup:provision',
    description: 'Turn confirmed self-service signups into tenants, one at a time',
)]
final readonly class ProvisionSignupsCommand
{
    public function __construct(
        private SignupRequestRepository $signups,
        private SignupProvisioner $provisioner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Provision only the signup made from this email address')]
        ?string $email = null,
    ): int {
        $signups = $email !== null
            ? self::onlyConfirmed($this->signups->findOneByEmail($email))
            : $this->signups->findConfirmed();

        if ($signups === []) {
            if ($email !== null) {
                // Asking for one by name and being given none is a typo or a
                // misremembered address, which is a failure of the invocation
                // rather than the state of the queue. The unfiltered run below
                // says the opposite for the opposite reason.
                $io->error(sprintf('No confirmed signup from "%s".', $email));

                return Command::FAILURE;
            }

            $io->success('No confirmed signups waiting.');

            return Command::SUCCESS;
        }

        $failed = [];
        $rows = [];

        foreach ($signups as $signup) {
            // **One at a time**, and the loop is sequential for a reason beyond
            // simplicity: each turn creates a Postgres role and a database,
            // migrates a schema and opens an SMTP connection, and doing several
            // of those at once against one cluster is how a provisioning run
            // becomes the reason everybody else's queries are slow. There is
            // nobody waiting on this, so there is nothing to buy with the
            // concurrency.
            $outcome = $this->provisioner->provision($signup);

            if ($outcome->hasFailed()) {
                // The sentence is built here rather than in the section that
                // prints it, where it would read more tidily, because this is
                // where the outcome is *known* to be a failure —
                // {@see SignupProvisioningOutcome::hasFailed()} asserts that the
                // stage and the reason are both there, and an array of outcomes
                // carries that knowledge nowhere. Reconstructing it downstream
                // would mean a `?? 'unknown'` branch that can never run, written
                // for the benefit of a static analyser rather than a reader.
                $failed[] = sprintf(
                    ' <error>%s</error> (%s, attempt %d): %s',
                    $outcome->email,
                    $outcome->stage->value,
                    $outcome->attempts,
                    $outcome->reason,
                );
            }

            $rows[] = self::row($signup, $outcome);
        }

        $io->table(['Email', 'Name', 'Tenant', 'Hostname', 'Result'], $rows);

        if ($failed !== []) {
            // The driver's, the provisioner's and the mailer's own words, here
            // and nowhere else. They name hosts, ports and roles, which is fine
            // in the terminal of somebody who already holds the DSN and is
            // exactly why they are not written onto the signup row — see
            // SignupProvisioningStage, and TenantUsage for where that rule was
            // settled first.
            $io->section('Could not be provisioned');

            foreach ($failed as $line) {
                $io->text($line);
            }

            $io->newLine();
            $io->error(sprintf(
                '%d of %d signup(s) could not be provisioned. The rest were, and their first users have been invited.',
                \count($failed),
                \count($signups),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d signup(s) provisioned.', \count($signups)));

        return Command::SUCCESS;
    }

    /**
     * One line of the report.
     *
     * A failed signup says so in the result column and still shows the name and
     * hostname it was aiming at, because those are what an operator has to
     * compare against the registry to work out what went wrong. Where a failure
     * came before the name could even be translated the cells are em dashes,
     * which is the honest rendering and the one [XIV-58]'s page uses for a
     * tenant that has no domain yet.
     *
     * @return list<string>
     */
    private static function row(SignupRequest $signup, SignupProvisioningOutcome $outcome): array
    {
        $result = match (true) {
            $outcome->hasFailed() => sprintf('failed at %s', $outcome->stage->value),
            // Worth distinguishing in the output even though the two end in the
            // same place: "resumed" is this run finishing what an earlier one
            // started, and somebody reading a report full of them is reading
            // evidence that runs keep dying half way through.
            $outcome->resumed => 'resumed and finished',
            default => 'provisioned, invitation sent',
        };

        return [
            $outcome->email,
            $signup->getCompanyName(),
            $outcome->tenantSlug ?? '—',
            $outcome->hostname ?? '—',
            $result,
        ];
    }

    /**
     * A pending row is not this command's business, and `--email` is the one way
     * one could arrive here.
     *
     * The bulk query filters on the status in SQL; a lookup by address does not,
     * because the unique index is on the address alone. Dropping it here rather
     * than provisioning it is the whole of §8.12's gate: an address typed into a
     * form proves nothing, and a `--email` that quietly bypassed confirmation
     * would be a way to create a tenant for somebody who never asked. The caller
     * is then told there is no confirmed signup from that address, which is
     * true and does not report whether an unconfirmed one exists.
     *
     * @return list<SignupRequest>
     */
    private static function onlyConfirmed(?SignupRequest $signup): array
    {
        return $signup !== null && $signup->getStatus() === SignupStatus::Confirmed ? [$signup] : [];
    }
}

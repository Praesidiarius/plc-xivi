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

use App\Deployment\TrustedHosts;
use App\Registry\Repository\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Which of this installation's own hostnames the trusted-host pattern would
 * refuse (XIV-93, docs/architecture/deployment.md §4.3).
 *
 * ## The failure mode this exists for
 *
 * A `trusted_hosts` pattern that is too narrow takes a paying customer's
 * installation dark, and the symptom is a **bare 400** — no page, no field
 * naming the header, nothing in the response to work from. Worse, whoever finds
 * out is the customer, and the first thing they find out is that their software
 * is broken.
 *
 * `App\Deployment\TrustedHosts` removes most of that class of mistake by
 * composing the pattern instead of accepting one, and by adding
 * `app.system_hosts` to it so the control plane cannot be left out. What remains
 * is the one thing composition cannot decide: whether the domains a deployment
 * named actually cover the hostnames its customers are on. That is a question
 * about the registry, so it needs a database, so it is a command.
 *
 * ## Where it runs, and the one place it deliberately does not
 *
 * `bin/deploy` runs it, between the control-plane migration and the tenant
 * migrations — the earliest moment at which the registry is readable, and
 * before the serving containers are replaced, which is what makes finding out
 * free. A non-zero exit there stops the deploy, and that is the right cost: the
 * alternative is switching traffic onto a release that 400s a customer.
 *
 * **The container entrypoint runs it too and ignores its exit code**, which
 * looks like a check nobody enforces and is the opposite of `deploy:check-secrets`
 * next to it. The asymmetry is deliberate and is about blast radius. A published
 * secret is a property of the *instance*, so refusing to start denies exactly
 * the thing that must not run. A hostname outside the pattern is a property of
 * **one customer**, who is already dark — and refusing to start over it would
 * take every other customer dark to protect them. So the entrypoint's copy is a
 * diagnostic rather than a gate: it puts the pattern, and the names it refuses,
 * into `docker logs` on every start, which is where somebody chasing an
 * unexplained 400 is already looking.
 *
 * ## The exit codes
 *
 * Borrowed from `tenant:migrate` on purpose (§4.2), so that a deploy script
 * reads the two the same way rather than learning a second convention:
 *
 * | code | meaning |
 * | --- | --- |
 * | 0 | every hostname this installation serves is admitted — or nothing is configured, and it answers to everything |
 * | 1 | the check could not happen: the registry could not be read |
 * | 3 | the check happened and at least one hostname would be refused |
 *
 * Three rather than two for `tenant:migrate`'s reason: `Command::INVALID` is 2
 * and means "you typed the command wrong" everywhere else.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'deploy:check-hosts',
    description: 'Report which hostnames this installation answers to, and which of its own it would refuse',
)]
final readonly class CheckTrustedHostsCommand
{
    /** At least one hostname this installation is supposed to serve is outside the pattern. */
    private const int SOME_REFUSED = 3;

    public function __construct(
        private TrustedHosts $trustedHosts,
        private TenantRepository $tenants,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->trustedHosts->isConfigured()) {
            // Not an error, and said in full rather than passed over. An
            // installation with no pattern answers to any `Host` header that
            // reaches it, which is how this application behaved before XIV-93
            // and is what development needs — but it is also a fact an operator
            // should be able to read off a deploy log rather than infer from an
            // absence.
            $io->writeln(sprintf(
                '<comment>Hostnames:</comment> %s is empty, so the Host header is not checked. This '
                . 'installation answers to any hostname that reaches it.',
                TrustedHosts::VARIABLE,
            ));
            $io->writeln(
                '  That is the default and is correct for development. On a real deployment, set it '
                . "to the domains your customers are served under\n"
                . '  (see https://praesidiarius.github.io/plc-xivi-docs/running/hostnames/).',
            );

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            '<info>Hostnames:</info> %s admits %s and every name under them.',
            TrustedHosts::VARIABLE,
            implode(', ', $this->trustedHosts->domains()),
        ));
        $io->writeln(sprintf(
            '  Served without a tenant and admitted by construction: %s.',
            implode(', ', $this->trustedHosts->alwaysAdmitted()),
        ));

        try {
            $tenants = $this->tenants->findAllWithDomains();
        } catch (\Throwable $e) {
            // A registry that cannot be read is not a pass. The question this
            // command answers is "would any of my customers get a 400", and
            // "cannot tell" is not an answer to resolve in favour of deploying —
            // the same call PlaceholderSecretGuard makes about an unreadable
            // `.env` (§4.2).
            $io->getErrorStyle()->error(sprintf(
                'The tenant registry could not be read, so it is not known whether any customer '
                . 'hostname would be refused: %s',
                $e->getMessage(),
            ));

            return Command::FAILURE;
        }

        $refused = [];

        foreach ($tenants as $tenant) {
            foreach ($tenant->getDomains() as $domain) {
                $hostname = $domain->getHostname();

                if ($this->trustedHosts->admits($hostname)) {
                    continue;
                }

                $refused[] = [
                    $hostname,
                    $tenant->getSlug(),
                    $tenant->getStatus()->value,
                    // A tenant that is suspended or still provisioning is not
                    // being served anyway, so its hostname being outside the
                    // pattern costs nobody anything today. It is still printed,
                    // because it costs somebody everything on the day that
                    // tenant is reinstated — and a deploy stopped by a
                    // suspended customer's hostname is a deploy stopped for a
                    // reason nobody would thank us for.
                    $tenant->getStatus()->servesRequests() ? 'yes' : 'no',
                ];
            }
        }

        if ($refused === []) {
            $io->writeln(sprintf(
                '  Every hostname of all %d tenants in the registry is admitted.',
                \count($tenants),
            ));

            return Command::SUCCESS;
        }

        // Whether anybody is dark *now* is what decides the exit code, and
        // therefore whether a deploy stops. A refused hostname belonging to a
        // suspended or half-provisioned tenant is worth printing and is not
        // worth blocking a release over — nobody is being served on it either
        // way, and a deploy held up by a customer who was suspended in March is
        // how a gate comes to be run with `|| true`.
        $dark = \array_any($refused, static fn (array $row): bool => $row[3] === 'yes');

        $error = $io->getErrorStyle();
        $message = sprintf(
            '%d hostname%s in this registry %s answered with a bare 400.',
            \count($refused),
            \count($refused) === 1 ? '' : 's',
            $dark ? 'would be' : 'would be, if the tenant holding it were serving requests,',
        );

        $dark ? $error->error($message) : $error->warning($message);

        $error->table(['Hostname', 'Tenant', 'Status', 'Serving today'], $refused);

        $error->writeln(sprintf(
            "A request to one of those names fails with HTTP 400 and nothing in the body to explain it.\n"
            . "Either add the domain it sits under to %s, or move the tenant to a hostname\n"
            . "under one that is already listed (bin/console tenant:list shows what each one has).\n\n"
            . 'See https://praesidiarius.github.io/plc-xivi-docs/running/hostnames/ and docs/architecture/deployment.md §4.3.',
            TrustedHosts::VARIABLE,
        ));

        return $dark ? self::SOME_REFUSED : Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\ControlPlane\Command;

use App\Tenancy\Security\TenantSecretRotator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step three of a key rotation: add the new key to TENANT_SECRET_KEYS, point
 * TENANT_SECRET_KEY_ID at it, run this, then drop the old key once it reports
 * everything on the active key.
 */
#[AsCommand(
    name: 'tenant:rotate-secrets',
    description: 'Re-encrypt tenant database passwords with the active secret key',
)]
final readonly class RotateTenantSecretsCommand
{
    public function __construct(private TenantSecretRotator $rotator)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $report = $this->rotator->rotate();

        $io->writeln(sprintf('Active key: <info>%s</info>', $report->activeKeyId));
        $io->writeln(sprintf(
            '%d rotated, %d already current.',
            \count($report->rotated),
            \count($report->skipped),
        ));

        if ($report->rotated !== []) {
            $io->listing($report->rotated);
        }

        if (!$report->isComplete()) {
            foreach ($report->failed as $slug => $reason) {
                $io->writeln(sprintf('<error>%s</error>: %s', $slug, $reason));
            }

            $io->error(sprintf(
                '%d tenant(s) could not be rotated. Keep the previous key in TENANT_SECRET_KEYS until '
                . 'this command reports none.',
                \count($report->failed),
            ));

            return Command::FAILURE;
        }

        $io->success('Every tenant secret is on the active key; previous keys can be removed.');

        return Command::SUCCESS;
    }
}

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

use App\Tenancy\Security\TenantSecretRotator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step three of a key rotation: add the new key to TENANT_SECRET_KEYS, point
 * TENANT_SECRET_KEY_ID at it, run this, then drop the old key once it reports
 * everything on the active key.
 *
 * It reaches every tenant's own database as well as the registry, because a
 * customer's outgoing-mail password is stored there (XIV-37) — so this takes as
 * long as there are customers, and the report is what says it is safe to drop
 * the previous key rather than the exit status alone.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:rotate-secrets',
    description: 'Re-encrypt tenant database and outgoing-mail passwords with the active secret key',
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

        // Named separately because it is the half an operator does not know
        // exists (XIV-37): a customer's outgoing-mail password lives in their own
        // database, and a rotation that had not said so would look complete
        // while leaving it behind.
        if ($report->mailRotated !== []) {
            $io->writeln(sprintf(
                '%d outgoing-mail password(s) moved with them:',
                \count($report->mailRotated),
            ));
            $io->listing($report->mailRotated);
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

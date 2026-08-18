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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Security\OperatorManager;

/**
 * Who can sign in to this installation (XIV-92).
 *
 * The first question anybody asks when they suspect something, and until this
 * command the only way to answer it was `select * from operator` — which is to
 * say, the answer lived behind a database client, on a machine where the person
 * asking may not have one, in a table whose columns they would have to know.
 * §4.1 makes the general form of that argument about tenants; this is the
 * cheapest possible instance of it.
 *
 * **Revoked operators are listed, marked rather than hidden**, and that is the
 * design decision in this file. A list that showed only working accounts would
 * make "revoked yesterday" and "never existed" the same answer, and telling
 * those two apart is precisely what somebody investigating a leaked credential
 * is here to do. The status column carries it, and the summary line under the
 * table repeats the count, because the number of people who can currently get in
 * is the fact this command exists to state.
 *
 * **No password material of any kind**, not even a hash prefix or a "has a
 * password" column. There is nothing here a hash could answer that the sign-in
 * page cannot, and a command that prints part of one is a command somebody
 * pastes into a ticket.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'control:operator:list',
    description: 'List everybody who can sign in to the control plane',
)]
final readonly class ListOperatorsCommand
{
    public function __construct(private OperatorManager $operators)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $operators = $this->operators->all();

        if ($operators === []) {
            // A warning rather than an empty table, and it names the way out.
            // An installation with no operator is not a tidy state: nobody can
            // reach the control plane at all, and the console is the only place
            // that can be fixed from.
            $io->warning(
                'No operators exist, so nobody can sign in to the control plane. '
                . 'Create one with "bin/console control:operator:create".',
            );

            return Command::SUCCESS;
        }

        $rows = [];
        $active = 0;

        foreach ($operators as $operator) {
            $active += $operator->isActive() ? 1 : 0;

            $rows[] = [
                $operator->getEmail(),
                $operator->getName(),
                $operator->isActive() ? 'active' : '<comment>revoked</comment>',
                $operator->getCreatedAt()->format('Y-m-d H:i'),
                // What `updatedAt` means here is "when this row last changed",
                // which for a revoked operator is when they were revoked and for
                // everybody else is when their password was last set. Headed
                // "Changed" rather than "Revoked" because it is honestly the
                // first of those; a column that claimed to be the second would
                // be wrong on every active row.
                $operator->getUpdatedAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(['Email', 'Name', 'Status', 'Created', 'Changed'], $rows);

        $io->writeln(sprintf(
            ' %d of %d can sign in.',
            $active,
            \count($operators),
        ));
        $io->newLine();

        return Command::SUCCESS;
    }
}

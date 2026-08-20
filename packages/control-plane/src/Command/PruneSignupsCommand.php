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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Repository\SignupRequestRepository;

/**
 * Throws away signups whose address never answered (XIV-125).
 *
 * ## The decision this command is, stated before anything about how it works
 *
 * **An unconfirmed signup is discarded; a confirmed one and a tenant never
 * are.** Nothing cleaned up after §8.12's table before this, so a form somebody
 * filled in wrongly in March was still a row with their address and their
 * company's name in it a year later, held against a confirmation window that
 * closed after a day. That row is the personal data of somebody who is not a
 * customer, was never a customer, and did not finish asking to be one, which is
 * the weakest claim to keeping anything that this system has. {@see
 * SignupRequest} already refuses to store an IP or a user agent for exactly that
 * reason, and this is the same argument applied to the row itself once it is
 * certain that nobody is waiting on it.
 *
 * **What it removes is one row and nothing else.** No database, no role, no
 * tenant, no hostname: a pending signup holds *nothing*, which is §8.12's
 * anti-squatting rule seen from the other side (`reserved_slug` is NULL until an
 * address answers, and that is how the schema says a row holds no name). So this
 * is a delete with no consequences anywhere else in the system, which is the
 * only kind this repository is willing to put on a schedule. Deleting a
 * *customer's* database on a timer is something this project may never do: §4.1
 * makes deprovision loud, interactive and refused unattended, and §4.6 says no
 * automatic state may destroy data on its own. An abandoned **tenant** is
 * therefore reported and left alone, by `tenant:usage:collect`, and this command
 * cannot see one.
 *
 * ## Thirty days, and why not one
 *
 * The confirmation window is twenty-four hours
 * ({@see \Xivi\ControlPlane\Signup\SignupIntake::CONFIRMATION_WINDOW}), so the
 * obvious cutoff is the window itself: the moment a link stops working, the row
 * behind it is dead. That is true and it costs something a month of patience
 * buys back. Somebody who opens a three-day-old mail and follows the link is
 * told, today, that their confirmation *expired*, a sentence that explains what
 * happened and what to do about it. Delete the row and the same click says the
 * link is unknown, which reads as "we have never heard of you" and is the answer
 * a phishing check gives. §8.12 was careful to make those two different answers
 * ({@see \Xivi\ControlPlane\Signup\ConfirmationOutcome}), and it would be a
 * strange trade to spend that on a table of a few hundred rows.
 *
 * So the cutoff is thirty days *past* the window, which is far outside the range
 * in which anybody is still clicking anything and far inside "we kept it
 * forever". It is a constant rather than an option, because a deployment that
 * can set it to a day gets the behaviour above by accident, and because it is
 * one of those numbers that only ever gets tuned in the direction of forgetting
 * why it was chosen.
 *
 * ## The run
 *
 * Removing nothing is a success and says so quietly: this is nightly, on an
 * installation where most nights there is nothing to do, and a cron entry that
 * mails somebody every night is a cron entry nobody reads within a fortnight
 * (the argument {@see ProvisionSignupsCommand} makes for an empty queue).
 * `--dry-run` prints exactly what it would remove and removes nothing, because a
 * command that deletes should be answerable to somebody who wants to look first.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'signup:prune',
    description: 'Discard self-service signups whose address never confirmed',
)]
final readonly class PruneSignupsCommand
{
    /**
     * How long after its confirmation window a pending signup is kept.
     *
     * Thirty days, and the class docblock has the argument. Public so that a
     * test can state the same number once rather than encode it twice.
     */
    public const string GRACE = 'P30D';

    public function __construct(
        private EntityManagerInterface $controlPlane,
        private SignupRequestRepository $signups,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Print what would be removed and remove nothing')]
        bool $dryRun = false,
    ): int {
        $cutoff = new \DateTimeImmutable()->sub(new \DateInterval(self::GRACE));
        $abandoned = $this->signups->findAbandonedPending($cutoff);

        if ($abandoned === []) {
            $io->success('No unconfirmed signups old enough to discard.');

            return Command::SUCCESS;
        }

        // The addresses are printed. This is a console an operator is at, they
        // already hold the credential to the database these rows are in, and the
        // whole value of the report is being able to see that what went was what
        // should have gone. It is the same treatment `signup:provision` gives the
        // queue it walks, and the opposite of what reaches a web page.
        $io->table(
            ['Email', 'Name', 'Submitted', 'Link expired'],
            array_map(self::row(...), $abandoned),
        );

        if ($dryRun) {
            $io->note(sprintf('%d unconfirmed signup(s) would be discarded. Nothing was removed.', \count($abandoned)));

            return Command::SUCCESS;
        }

        foreach ($abandoned as $signup) {
            $this->controlPlane->remove($signup);
        }

        // One flush for the run. The rows are independent and nothing references
        // them, so there is no order to get right and no half-done state worth
        // protecting against: a failure here removes none of them and the next
        // run finds the same set.
        $this->controlPlane->flush();

        $io->success(sprintf(
            '%d unconfirmed signup(s) discarded. Nothing else was removed: a pending signup holds no name and no database.',
            \count($abandoned),
        ));

        return Command::SUCCESS;
    }

    /**
     * One line of the report.
     *
     * Both dates, because they answer different questions: when somebody filled
     * the form in is how a reader recognises a campaign or a script, and when the
     * link died is what makes the row eligible at all.
     *
     * @return list<string>
     */
    private static function row(SignupRequest $signup): array
    {
        return [
            $signup->getEmail(),
            $signup->getCompanyName(),
            $signup->getCreatedAt()->format('Y-m-d'),
            $signup->getConfirmationExpiresAt()->format('Y-m-d'),
        ];
    }
}

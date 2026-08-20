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

namespace Xivi\ControlPlane\Signup;

use Doctrine\ORM\EntityManagerInterface;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Repository\SignupRequestRepository;
use Xivi\ControlPlane\View\StalledSignupListing;

/**
 * The people who confirmed an address, were told their installation was being
 * prepared, and will never get one unless somebody intervenes (XIV-108, §8.14).
 *
 * ## The gap this closes, stated precisely
 *
 * When `signup:provision` fails, three things notice and all three are addressed
 * to the operator: the non-zero exit that makes cron mail somebody, the half-made
 * tenant at the top of the tenant list, and the three provisioning columns on the
 * signup row. Nothing reaches the person waiting.
 *
 * **For nearly every failure that silence is right.** A mail server that was busy,
 * a cluster that refused a connection, a migration that timed out: the next run
 * fixes all of them, and "we could not set up your installation" followed twenty
 * minutes later by "here is your login" is a worse experience than twenty minutes
 * of quiet. The gap is the signup that fails at `preflight`, which fails at it
 * every run for ever, because an operator took the translated name by hand between
 * the confirmation and the next cron run. Nothing resolves that but a person.
 *
 * ## Why nothing here is automatic
 *
 * There is no threshold in this class: not in attempts, not in stages, not in
 * elapsed time. The two sentences an automatic mail could send are both wrong
 * somewhere it would fire:
 *
 *   * *"Something went wrong and we are looking into it"* commits a human being
 *     to looking, which no counter can know anybody is doing.
 *   * *"That address is no longer available"* is accurate for `preflight` and
 *     false for every other stage.
 *
 * The third option is the one that cannot be wrong, and it is what this class
 * implements: **an operator sees who is waiting, reads the message, and presses
 * send.** A human stays in the loop for what is in substance an apology, and the
 * question "how many attempts is enough" stops being a question rather than
 * getting an invented answer. That also disposes of the fact that an attempt
 * count means whatever the local crontab decides it means.
 *
 * The automatic path is untouched. `signup:provision` keeps its exit codes, its
 * stage recording and its attempt counter exactly as they were, and nothing here
 * runs on a schedule.
 *
 * ## One notion of stuck, borrowed rather than invented
 *
 * {@see \Xivi\ControlPlane\Provisioning\SignupProvisioningStage::isWorthRetrying()}
 * already draws the line between a failure that clears itself and one that never
 * will, and it was written to answer this exact question. So it is the hook,
 * through {@see SignupRequest::provisioningIsStalled()}, and there is no second
 * definition anywhere in this feature. A stage that stops being retryable later
 * arrives on this page by itself, which is the property that made the enum worth
 * reusing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class StalledSignups
{
    public function __construct(
        /**
         * The control plane's, which is the default manager and the only database
         * anything here touches. A stranded signup has no tenant to connect to,
         * which is the whole shape of the problem.
         */
        private EntityManagerInterface $controlPlane,
        private SignupRequestRepository $signups,
        private SignupMailer $mailer,
    ) {
    }

    /**
     * Everybody an operator should be looking at, with the message each of them
     * would be sent.
     *
     * **The preview is built here rather than on demand**, one Twig render per
     * row, because the alternative is a second request to fetch it and a screen
     * where the operator has to ask for the text before they can approve it. The
     * list is empty on an installation where nothing has gone wrong and a
     * handful on one where something has, so the cost is a rounding error on a
     * page that already makes three queries.
     *
     * @return list<StalledSignupListing>
     */
    public function listing(): array
    {
        return array_values(array_map(
            fn (SignupRequest $signup): StalledSignupListing => StalledSignupListing::of(
                $signup,
                $this->mailer->previewApology($signup),
            ),
            $this->stalled(),
        ));
    }

    /**
     * One of them by id, or null.
     *
     * **Null for a signup that is not stalled, not merely for one that is not
     * there**, and the two are deliberately the same answer. Between the page
     * being drawn and the button being pressed a signup can be provisioned by
     * the cron run (the row is deleted), or an operator can release the name it
     * was waiting for and the next run succeeds. In both cases the person is no
     * longer waiting, and the honest response to a POST about them is the same:
     * this is no longer something to act on. Letting the caller distinguish the
     * two would only let it write two messages that mean the same thing.
     */
    public function find(int $id): ?SignupRequest
    {
        $signup = $this->signups->find($id);

        return $signup instanceof SignupRequest && $signup->provisioningIsStalled() ? $signup : null;
    }

    /**
     * Send the message, then write down that it was sent.
     *
     * **That order, and it is the important line in this class.** §8.7 refuses to
     * let a failed send look like a successful one, so the record is written
     * after the transport has accepted the message: {@see SignupMailer} throws
     * {@see SignupMailFailed} on any failure, which leaves this method before it
     * reaches the row, and the button is still there on the next page load. The
     * other order would mark somebody as written to on the strength of an attempt
     * that never left the building, and the specific harm is that the second
     * operator would then believe the first had dealt with it.
     *
     * @return bool false when this person has already been written to, in which
     *              case nothing was sent and nothing was written. That is the
     *              answer to two operators with the same page open, and it is a
     *              return value rather than an exception because it is an
     *              ordinary outcome of a shared screen rather than a fault.
     *              {@see SignupRequest::recordApology()} still throws underneath,
     *              for the caller that has not read this line.
     *
     * @throws SignupMailFailed when the message could not be sent, in which case
     *                          nothing is recorded
     * @throws \LogicException  when asked about a signup that is not stalled,
     *                          which is a caller that skipped {@see find()}
     */
    public function apologise(SignupRequest $signup, string $operatorLabel): bool
    {
        if (!$signup->provisioningIsStalled()) {
            throw new \LogicException(sprintf(
                'Signup %d is not stalled and has nothing to apologise for.',
                $signup->getId() ?? 0,
            ));
        }

        if ($signup->getApologySentAt() !== null) {
            return false;
        }

        $this->mailer->sendApology($signup);

        $signup->recordApology($operatorLabel);
        $this->controlPlane->flush();

        return true;
    }

    /**
     * The failed signups that will never resolve, in the order the repository
     * found them.
     *
     * The filter is in PHP for the reason {@see SignupRequestRepository::findFailed()}
     * gives: `isWorthRetrying()` is a `match` over the enum, and a DQL predicate
     * naming the cases would be a second copy of it that nothing would notice
     * diverging.
     *
     * @return list<SignupRequest>
     */
    private function stalled(): array
    {
        return array_values(array_filter(
            $this->signups->findFailed(),
            static fn (SignupRequest $signup): bool => $signup->provisioningIsStalled(),
        ));
    }
}

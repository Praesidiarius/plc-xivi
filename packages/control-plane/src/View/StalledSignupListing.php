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

namespace Xivi\ControlPlane\View;

use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Provisioning\SignupProvisioningStage;
use Xivi\ControlPlane\Signup\ApologyPreview;

/**
 * One person waiting on an installation that will never arrive, as the tenant
 * list draws them (XIV-108, §8.14).
 *
 * The shape {@see TenantSummary}, {@see SignupRefusalListing} and
 * {@see SupportRequestListing} all have, and it is the page's rule rather than a
 * judgement about this entity: **no entity reaches that template**, because a
 * rule with an exception in it is a rule somebody has to weigh every time
 * somebody adds a variable. Here the entity also carries
 * `confirmation_token_hash`, which is a digest and not a token and still has no
 * business being one `dump()` away from a browser.
 *
 * ## This one carries an address, and that is a departure worth stating
 *
 * §8.11 keeps a customer's people out of the control plane, and §8.15 refused a
 * contact column on a purchase request on exactly that argument. Neither applies
 * here, and the reason is what the whole feature is about: **this person is not a
 * customer's user, they are the platform's own correspondent.** They gave this
 * address to this installation, on this installation's own form, and it is
 * already stored on the row because provisioning has to write to it. The operator
 * is being asked to send them a message; a screen that hid who it was going to
 * would be asking somebody to approve a letter with the envelope covered up.
 *
 * ## The message is a value here, not a description of one
 *
 * {@see $subject} and {@see $body} are the mail's own subject and its own
 * plain-text body, rendered in the language the signup recorded. The template
 * prints them and adds nothing. That is what makes "the operator sees what it
 * says before it goes" checkable: there is no second sentence anywhere for the
 * screen to show instead.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class StalledSignupListing
{
    private function __construct(
        public int $id,
        /** Where the message goes. See the class docblock for why it is drawn. */
        public string $email,
        public string $companyName,
        /** The name they asked for, which for a `preflight` failure is the name somebody else now holds. */
        public string $slug,
        /** The language the message is written in, drawn because an operator reading it needs to know why it is not English. */
        public string $locale,
        /** Which step it stopped at, drawn as the enum so the template cannot invent a sixth. */
        public SignupProvisioningStage $stage,
        /** How many runs have tried. Not a threshold for anything: see {@see \Xivi\ControlPlane\Signup\StalledSignups}. */
        public int $attempts,
        public \DateTimeImmutable $failedAt,
        /** When the address answered, which is when this person started waiting. */
        public ?\DateTimeImmutable $confirmedAt,
        public string $subject,
        public string $body,
        /** Null until somebody has written to them, which is also what decides whether the button is offered. */
        public ?\DateTimeImmutable $sentAt,
        /** And who did, copied off the row. Null exactly when {@see $sentAt} is. */
        public ?string $sentBy,
    ) {
    }

    public static function of(SignupRequest $signup, ApologyPreview $message): self
    {
        $stage = $signup->getProvisioningStage();
        $failedAt = $signup->getProvisioningFailedAt();

        // Both are non-null for anything this class is ever built from, because
        // `provisioningIsStalled()` is false without a stage and the three
        // provisioning columns are written together or not at all. Asserted
        // rather than made nullable, so that the template has two fewer branches
        // that could only ever be reached by a row this page does not select.
        \assert($stage instanceof SignupProvisioningStage);
        \assert($failedAt instanceof \DateTimeImmutable);

        return new self(
            $signup->getId() ?? 0,
            $signup->getEmail(),
            $signup->getCompanyName(),
            $signup->getSlug(),
            $signup->getLocale(),
            $stage,
            $signup->getProvisioningAttempts(),
            $failedAt,
            $signup->getConfirmedAt(),
            $message->subject,
            $message->body,
            $signup->getApologySentAt(),
            $signup->getApologySentBy(),
        );
    }

    /**
     * Whether the button is still offered for this row.
     *
     * The one question the two recorded columns are read for, named here so that
     * the template asks it in words rather than testing a timestamp against null
     * and leaving the reader to work out what that means.
     */
    public function awaitsAMessage(): bool
    {
        return $this->sentAt === null;
    }
}

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

namespace Xivi\ControlPlane\Provisioning;

/**
 * What one signup's turn produced (XIV-98).
 *
 * The same shape {@see \Xivi\ControlPlane\Usage\CollectionOutcome} has and for
 * the same reason: over a queue of customers, one of them failing is an
 * **ordinary outcome of the run** rather than an exception to it. The
 * provisioner therefore returns this instead of throwing, and the caller's job
 * is to write the failure down, say so, and move on to the next person — which
 * is the "one failure must not cost the others" rule expressed as a return type
 * rather than as a `try` somebody has to remember to write.
 *
 * ### The reason is a sentence for a terminal and is not stored
 *
 * {@see $reason} carries the driver's or the mailer's own words, which name
 * hosts, ports and roles. That is exactly right in front of somebody who already
 * holds the DSN and exactly wrong on a row — [XIV-59] settled it one table along
 * and {@see SignupProvisioningStage} carries the argument. So this object holds
 * both halves and they go to different places: the stage onto the signup row,
 * the sentence into the run's output and nowhere else.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupProvisioningOutcome
{
    /**
     * @param string                       $email      who signed up, which is the only identifier that
     *                                                 survives every outcome — a failure at
     *                                                 {@see SignupProvisioningStage::Preflight} has no tenant
     *                                                 and no hostname to name
     * @param string                       $signupSlug the hostname-safe name they were promised
     * @param string|null                  $tenantSlug what it translates to, or null when it does not
     * @param string|null                  $hostname   where they would be served, or null when signup is off
     * @param SignupProvisioningStage|null $stage      where it stopped, or null when it did not
     * @param string|null                  $reason     the failure in its own words, for the terminal
     * @param int                          $attempts   how many times this signup has now failed, read off the
     *                                                 row the provisioner has just written, so the report and
     *                                                 the next run agree about the number
     * @param bool                         $resumed    whether this run finished work a previous one started
     */
    private function __construct(
        public string $email,
        public string $signupSlug,
        public ?string $tenantSlug = null,
        public ?string $hostname = null,
        public ?SignupProvisioningStage $stage = null,
        public ?string $reason = null,
        public int $attempts = 0,
        public bool $resumed = false,
    ) {
    }

    public static function provisioned(
        string $email,
        string $signupSlug,
        string $tenantSlug,
        string $hostname,
        bool $resumed = false,
    ): self {
        return new self($email, $signupSlug, $tenantSlug, $hostname, resumed: $resumed);
    }

    public static function failed(
        string $email,
        string $signupSlug,
        SignupProvisioningStage $stage,
        string $reason,
        int $attempts,
        ?string $tenantSlug = null,
        ?string $hostname = null,
    ): self {
        return new self($email, $signupSlug, $tenantSlug, $hostname, $stage, $reason, $attempts);
    }

    /**
     * Whether this signup is one the run has to report and exit non-zero for.
     *
     * The assertion is what lets a caller print {@see $stage}`->value` without a
     * null check that could never fire — the two are one fact written as two
     * fields, and this is the sentence that says so to a static analyser as well
     * as to a reader.
     *
     * @phpstan-assert-if-true !null $this->stage
     * @phpstan-assert-if-true !null $this->reason
     */
    public function hasFailed(): bool
    {
        return $this->stage !== null;
    }
}

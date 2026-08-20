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

use Xivi\ControlPlane\Entity\SignupRefusal;

/**
 * One throwaway provider as the tenant list draws it (XIV-125, §8.12).
 *
 * The same shape and the same reason as {@see TenantSummary} and
 * {@see TenantUsageSummary}: a readonly object of scalars built by one static
 * factory, so that what the template can see is decided in PHP where the
 * decision can carry a comment. This entity holds nothing dangerous, a domain
 * off a list this installation ships and two dates, and it still goes through
 * a view model, because the rule on that page is *no entity reaches the
 * template* rather than *no dangerous entity does*, and a rule with an exception
 * in it is a rule somebody has to judge each time.
 *
 * What it deliberately does not carry is anything about a person. There is no
 * address on the row to begin with (see {@see SignupRefusal}), which is the
 * point at which that was settled; this is the second place it would have to be
 * undone.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupRefusalListing
{
    private function __construct(
        public string $domain,
        public int $attempts,
        public \DateTimeImmutable $firstSeenAt,
        public \DateTimeImmutable $lastSeenAt,
    ) {
    }

    public static function of(SignupRefusal $refusal): self
    {
        return new self(
            $refusal->getDomain(),
            $refusal->getAttempts(),
            $refusal->getFirstSeenAt(),
            $refusal->getLastSeenAt(),
        );
    }
}

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

namespace App\Tenancy\Security;

/**
 * @phpstan-type Slug string
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RotationReport
{
    /**
     * Counted separately from $rotated rather than merged into it, because they
     * are answers to different questions an operator asks in the same minute:
     * "can I drop the old key?" is about every secret there is, and "what did
     * this actually touch?" is about the customers whose mail would have broken
     * if it had not (XIV-37).
     *
     * @param list<string>          $rotated     tenants whose database password moved onto the active key
     * @param list<string>          $mailRotated tenants whose outgoing-mail password moved with it
     * @param list<string>          $skipped     tenants already on the active key
     * @param array<string, string> $failed      slug => why it could not be rotated
     */
    public function __construct(
        public array $rotated,
        public array $mailRotated,
        public array $skipped,
        public array $failed,
        public string $activeKeyId,
    ) {
    }

    /** True once every tenant is on the active key, i.e. the old key can be dropped. */
    public function isComplete(): bool
    {
        return $this->failed === [];
    }
}

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

namespace App\Tenant\Attachment;

/**
 * How much of a filesystem one tenant is using (XIV-115).
 *
 * Two numbers, and they exist as a value rather than as a pair of return values
 * because the one place that reads them reads both: `tenant:deprovision` names
 * what is about to go beside the record count it already gives (§4.1), and a
 * confirmation that said "12 files" without saying how much would be a
 * confirmation nobody can weigh.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class AttachmentUsage
{
    public function __construct(
        public int $files,
        public int $bytes,
    ) {
    }
}

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

namespace App\ControlPlane\Entity;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum TenantStatus: string
{
    /** Being created; the database may not exist or may not be migrated yet. */
    case Provisioning = 'provisioning';

    case Trial = 'trial';

    case Active = 'active';

    /** Kept intact, but refuses to serve requests (unpaid, disputed, on hold). */
    case Suspended = 'suspended';

    public function servesRequests(): bool
    {
        return match ($this) {
            self::Active, self::Trial => true,
            self::Provisioning, self::Suspended => false,
        };
    }
}

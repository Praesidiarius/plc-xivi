<?php

declare(strict_types=1);

namespace App\ControlPlane\Entity;

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

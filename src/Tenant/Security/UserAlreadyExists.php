<?php

declare(strict_types=1);

namespace App\Tenant\Security;

use App\ControlPlane\Entity\Tenant;

final class UserAlreadyExists extends \RuntimeException
{
    public static function in(Tenant $tenant, string $email): self
    {
        return new self(sprintf(
            'Tenant "%s" already has a user with the email "%s".',
            $tenant->getSlug(),
            $email,
        ));
    }
}

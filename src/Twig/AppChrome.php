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

namespace App\Twig;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantContext;
use App\Tenant\Security\PermissionResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;

/**
 * What every signed-in page needs around its content: whose installation this is,
 * and which modules they have.
 *
 * A Twig global rather than something each controller remembers to pass, since
 * forgetting it would mean a page with no navigation. All three accessors are
 * lazy — the login page renders without a tenant and must not query for modules.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AppChrome
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MetadataRepository $metadata,
        private readonly PermissionResolver $permissions,
        private readonly Security $security,
    ) {
    }

    public function getTenant(): ?Tenant
    {
        return $this->context->tryGetTenant();
    }

    /**
     * The modules this person may actually open (§7.5).
     *
     * Filtered by `list`, because that is the permission the tab links to. A
     * module somebody cannot list is not a module they have, and showing it
     * would be an invitation to a 403 — navigation that advertises doors and
     * then refuses them is worse than navigation that is honest about the
     * building.
     *
     * Not a security control: the routes refuse on their own account. This is
     * what the person is told the application consists of.
     *
     * @return list<ModuleDefinition>
     */
    public function getModules(): array
    {
        if (!$this->context->hasTenant()) {
            return [];
        }

        $permissions = $this->permissions->forUser($this->security->getUser());

        return array_values(array_filter(
            $this->metadata->all(),
            static fn (ModuleDefinition $module): bool => $permissions->allows($module->getKey(), ModuleAction::List),
        ));
    }

    /**
     * Whether this customer has any modules at all, whoever is looking.
     *
     * The dashboard needs to tell two empty states apart: nothing is installed,
     * which an administrator can fix with a command, and nothing is *yours*,
     * which they cannot. Telling somebody with no permissions to run a console
     * command against their employer's database would be the wrong sentence in
     * every respect.
     */
    public function isAnyModuleInstalled(): bool
    {
        return $this->context->hasTenant() && $this->metadata->all() !== [];
    }
}

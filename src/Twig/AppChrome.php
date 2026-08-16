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
use App\Tenant\Security\PermissionArea;
use App\Tenant\Security\PermissionResolver;
use App\Tenant\Settings\InstanceName;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private readonly InstanceName $instance,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(default::APP_LOGO)%')]
        private readonly ?string $logo = null,
    ) {
    }

    public function getTenant(): ?Tenant
    {
        return $this->context->tryGetTenant();
    }

    /**
     * What to call this installation in the chrome (XIV-12).
     *
     * The customer's own company name when they have set one, and the registry's
     * label for them otherwise. Two facts rather than one: `tenant.name` is what
     * the operator filed them under and is not theirs to change, so a customer who
     * has said what they are called should be reading that instead of it.
     */
    public function getName(): string
    {
        return $this->instance->current();
    }

    /**
     * The mark this installation shows, or null when it has none (XIV-48).
     *
     * A logo is supplied by the deployment rather than committed — see
     * `assets/brand/README.md` — so *having* one is not the normal case. A clean
     * checkout has none, and so do CI and the production image build.
     *
     * **The file is checked for before a path is offered**, which is the whole
     * reason this is a method rather than a parameter read in a template.
     * AssetMapper throws on an asset that is not there, so a template calling
     * `asset()` on a missing logo would take the page down instead of falling
     * back to the name in text.
     */
    public function getLogo(): ?string
    {
        $name = trim((string) $this->logo);

        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            // A path rather than a name would reach outside the directory the
            // deployment was asked to fill, so it is refused rather than
            // resolved.
            return null;
        }

        return is_file($this->projectDir . '/assets/brand/' . $name) ? 'brand/' . $name : null;
    }

    /**
     * The profile's permission key, so a template can ask `can('view', ...)`
     * about it without holding the string itself.
     */
    public function getProfileArea(): string
    {
        return PermissionArea::Profile->value;
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

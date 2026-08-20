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

use App\Registry\Entity\Notice;
use App\Registry\Entity\Tenant;
use App\Tenancy\TenantContext;
use App\Tenant\Entity\User;
use App\Tenant\Notice\NoticeInbox;
use App\Tenant\Repository\TenantProfileRepository;
use App\Tenant\Security\PermissionArea;
use App\Tenant\Security\PermissionResolver;
use App\Tenant\Security\StoreAction;
use App\Tenant\Settings\InstanceName;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;

/**
 * What every signed-in page needs around its content: whose installation this is,
 * and which modules they have.
 *
 * A Twig global rather than something each controller remembers to pass, since
 * forgetting it would mean a page with no navigation. Every accessor that costs
 * a query is lazy, because the login page renders without a tenant and must not
 * query for modules, a logo or the operator's announcements.
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
        /** The operator's loud channel, read once per page; see getPageNotices(). */
        private readonly NoticeInbox $notices,
        /** The customer's own mark lives on this row; see getTenantLogo(). */
        private readonly TenantProfileRepository $profiles,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(default::APP_LOGO)%')]
        private readonly ?string $logo = null,
    ) {
    }

    /**
     * The every-page notices, once this request has asked for them.
     *
     * Null is "not asked yet" and is distinct from the empty array, which is the
     * ordinary answer and is what most requests get. See {@see getPageNotices()}.
     *
     * @var list<Notice>|null
     */
    private ?array $pageNotices = null;

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
     * The customer's own mark, as a URL, or null when they have not uploaded one
     * (XIV-49).
     *
     * **A URL rather than an asset path, which is why it is not `getLogo()`
     * above.** That one names a file the deployment dropped into `assets/brand`
     * and has to go through `asset()`; this one names a route serving bytes out
     * of the customer's database, and is already the address to put in a `src`.
     * Two different things that happen to end up in the same `<img>`, and the
     * templates choose between them — see `_brand_mark.html.twig`.
     *
     * **Lazy like everything else here.** No tenant means no query: the control
     * plane's own hosts render this page too, and a profile lookup on a
     * connection that is deliberately unusable would take them down.
     *
     * The fingerprint in the path is what lets the response be cached for a year
     * (TenantLogoController). Reading it costs the profile row, which the bar
     * above is already reading for the company name.
     */
    public function getTenantLogo(): ?string
    {
        if (!$this->context->hasTenant()) {
            return null;
        }

        $fingerprint = $this->profiles->current()->getLogoFingerprint();

        return $fingerprint === null
            ? null
            : $this->urls->generate('tenant_logo', ['fingerprint' => $fingerprint]);
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
     * The store's permission key, so a template can ask `can('browse', ...)`
     * about it (XIV-6).
     *
     * The same trick as the profile's above, and it has to be a second one: the
     * store is the second permission axis (§8.4.3), so its subject is not an area
     * and its verbs are not ModuleAction's.
     */
    public function getStore(): string
    {
        return StoreAction::SUBJECT;
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
     * What the operator of this installation is saying loudly enough that it
     * follows this person around (XIV-166, §8.16).
     *
     * **Here rather than in a Twig extension of its own**, because that is what
     * this class is: the things every signed-in page needs around its content,
     * fetched lazily so that the pages without a tenant do not pay for them. A
     * banner in the shell is exactly that shape, and `chrome.pageNotices` reads
     * in `_topbar.html.twig` the way `chrome.modules` two lines above it does.
     *
     * **Lazy on three conditions, and each of them is a real page.** No tenant is
     * the control plane's own hosts and the login screen, where the tenant
     * connection is deliberately unusable. No user is the same login screen after
     * the tenant has resolved. And a user who is not a {@see User} is the
     * operator signed in to the administration surface, who is not a member of
     * any customer's installation and is not being announced to.
     *
     * **Memoised, and that is a footgun being removed rather than an
     * optimisation.** One caller exists today and it renders once per page, so
     * the memo saves nothing; but a Twig global is reachable from every template
     * in the application, and the second call site somebody adds in a year should
     * not quietly double a query that runs on every request. Per request, because
     * the runtime is deliberately not a worker (§7.4, §9.2) and this object dies
     * with the response.
     *
     * @return list<Notice>
     */
    public function getPageNotices(): array
    {
        if ($this->pageNotices !== null) {
            return $this->pageNotices;
        }

        $reader = $this->security->getUser();

        if (!$reader instanceof User || !$this->context->hasTenant()) {
            return $this->pageNotices = [];
        }

        return $this->pageNotices = $this->notices->onEveryPage($reader);
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

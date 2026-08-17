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

namespace App\ControlPlane\View;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Entity\TenantStatus;
use App\ControlPlane\Entity\TenantUsage;

/**
 * One customer as the tenant list draws them, and — far more importantly —
 * nothing else about that customer (XIV-58).
 *
 * **This class exists because `Tenant` carries a credential.** The registry row
 * holds `database_dsn` and `database_password`, the second of them encrypted and
 * the first of them naming a host, a port, a database and a role. Neither belongs
 * on an HTML page, and the way they get onto one is never a decision anybody
 * makes: it is `{{ tenant|json_encode }}` in a Stimulus data attribute, a `dump()`
 * left in a template, a serializer normalising an entity for a fragment, a
 * profiler panel on a page somebody screenshots into a chat. Every one of those
 * is a mistake that reads as harmless while it is being made.
 *
 * So the defence is not care and it is not a `|escape` — it is that **the entity
 * never reaches the template**. This object holds seven scalars and two arrays.
 * Dump it, encode it, serialize it, hand it to a JavaScript component: there is
 * no credential in it to leak, because the mapping below is the only code that
 * ever reads one out of a `Tenant` and it does not read those two columns. That
 * is a property of the type rather than of the person editing the template next,
 * which is the only kind of property worth having here.
 *
 * `TenantLogoTest` made the same argument for a tenant's own settings row in
 * XIV-49 — one public column out of a row that also holds an SMTP password — and
 * asserted it from the other side, by fetching the response and looking for the
 * secret in it. `TenantListTest` does exactly that here, and the two halves are
 * both wanted: this class makes the leak impossible, and the test notices if
 * somebody later decides the entity would be more convenient after all.
 *
 * **It is also the boundary marker for the page's other property.** Everything on
 * this object comes out of the control-plane database, because everything on the
 * `Tenant` row does. A field that could not be filled in from a control-plane row
 * is a field that cannot go on this object, and that refusal is the point rather
 * than a limitation. See {@see \App\ControlPlane\Controller\TenantListController}
 * for the argument.
 *
 * **XIV-59 added usage without weakening that**, which is the only way it could
 * have been added. How many users a customer has, when anybody last signed in and
 * how many records are in there are facts about that customer's own database —
 * but they are not read here and they are not read on this request. A console
 * command collects them one tenant at a time and writes them into the control
 * plane, and what arrives on this object is a `tenant_usage` row: a control-plane
 * row like every other value here, carrying the moment it was collected so that
 * nobody reads a figure from March as a figure from this morning. Null when
 * nobody has collected that customer yet, which is a different statement from
 * zero and from failed — see {@see TenantUsageSummary}.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantSummary
{
    /**
     * @param list<string> $modules   enabled module keys, as the registry row records them
     * @param list<string> $hostnames every hostname routed here, the primary one first
     */
    private function __construct(
        public string $slug,
        public string $name,
        public TenantStatus $status,
        public string $plan,
        public ?string $primaryDomain,
        public array $hostnames,
        public array $modules,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $provisionedAt,
        /** What the last collection found, or null if there has never been one (XIV-59). */
        public ?TenantUsageSummary $usage,
    ) {
    }

    /**
     * **The one place a `Tenant` is read for this page**, which is what makes the
     * claim above checkable by reading a single method rather than a directory.
     *
     * Private constructor so it stays that way: an object of this type cannot be
     * built from anything but a registry row, so nobody can assemble a "summary"
     * somewhere else that quietly carries an extra field along.
     *
     * The usage row is *passed in* rather than reached through the tenant, and
     * that is deliberate (XIV-59): `Tenant` has no association to it, so there is
     * no property here that could quietly load a second table per row, and the
     * caller has to have fetched every collection in one query before it gets
     * here. See {@see \App\ControlPlane\Repository\TenantUsageRepository::byTenantId()}.
     */
    public static function of(Tenant $tenant, ?TenantUsage $usage = null): self
    {
        $hostnames = [];

        // The primary first, then the rest in whatever order the row lists them.
        // `getPrimaryDomain()` falls back to the first domain when none is
        // flagged, which is the honest answer for a tenant that was given several
        // hostnames and no opinion — but it means the two lists can name the same
        // domain twice, hence the filter rather than an unshift.
        $primary = $tenant->getPrimaryDomain()?->getHostname();

        foreach ($tenant->getDomains() as $domain) {
            if ($domain->getHostname() !== $primary) {
                $hostnames[] = $domain->getHostname();
            }
        }

        return new self(
            $tenant->getSlug(),
            $tenant->getName(),
            $tenant->getStatus(),
            $tenant->getPlan(),
            $primary,
            $primary === null ? $hostnames : [$primary, ...$hostnames],
            $tenant->getEnabledModules(),
            $tenant->getCreatedAt(),
            $tenant->getProvisionedAt(),
            $usage === null ? null : TenantUsageSummary::of($usage),
        );
    }

    /**
     * Whether this customer's hostname is being answered at all.
     *
     * The same predicate the tenancy listener applies per request, asked of a row
     * rather than of a request — so the page and the front door cannot disagree
     * about which customers are being served.
     */
    public function servesRequests(): bool
    {
        return $this->status->servesRequests();
    }

    /**
     * Bootstrap's name for the colour this status is drawn in.
     *
     * Kept here rather than in the template because it is the same judgement
     * {@see TenantStatus::attentionRank()} makes and it should not be possible for
     * the two to drift — a status ordered to the top of the list and drawn in the
     * same grey as everything else would be worse than either alone. Kept out of
     * the enum for the opposite reason: `TenantStatus` is a domain type that the
     * provisioner and the tenancy listener both depend on, and Bootstrap's palette
     * has no business in it.
     *
     * Colour is never the only signal. The status is spelled out in words in the
     * same cell, because roughly one reader in twelve cannot tell these hues
     * apart and because a badge with no text is unreadable in a screenshot.
     */
    public function statusVariant(): string
    {
        return match ($this->status) {
            TenantStatus::Provisioning => 'danger',
            TenantStatus::Suspended => 'warning',
            TenantStatus::Trial => 'info',
            TenantStatus::Active => 'success',
        };
    }
}

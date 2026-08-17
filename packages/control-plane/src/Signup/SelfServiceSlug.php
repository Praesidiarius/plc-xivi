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

namespace Xivi\ControlPlane\Signup;

use App\Tenancy\TenantResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;

/**
 * What a self-service customer is allowed to be called (XIV-64).
 *
 * ### There are two slug rules in this system and they are deliberately different
 *
 * {@see TenantProvisioner::SLUG_PATTERN} is `/^[a-z][a-z0-9_]{1,55}$/`. It
 * permits **underscores** and forbids **hyphens**, which is exactly backwards
 * for a string that is going to become a DNS label — `my_company.xivi.app` is
 * not a valid hostname, and no amount of care at the call site makes it one.
 *
 * **That pattern is not changed, and this is the note that stops somebody
 * unifying them.** It is right for what it guards: a provisioning slug also
 * becomes a PostgreSQL database and role name, where an underscore is the
 * ordinary separator and a hyphen would force every identifier to be quoted.
 * Every tenant that exists is named that way, and so is the entire test suite —
 * `test_picker_candidates` and two dozen like it. More to the point,
 * `provision()` never derives a hostname from a slug at all: hostnames are an
 * explicit parameter, so an operator provisioning by hand is free to route
 * `acme.example.com` at a tenant called `acme_ag` and nothing is inconsistent.
 *
 * Self-service is the case where nobody types the hostname. The slug *is* the
 * subdomain, so it has to satisfy the stricter of the two rules, which is this
 * one:
 *
 *     ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$
 *
 * A single DNS label as RFC 1123 allows it: lowercase alphanumerics and hyphens,
 * no leading or trailing hyphen, at most 63 characters. Anything this accepts is
 * accepted by nothing else — in particular it is *not* accepted by
 * `SLUG_PATTERN`, which is why a signup's slug is translated on the way into
 * provisioning by [XIV-98] rather than passed through. Two rules, both enforced,
 * neither pretending to be the other. docs/architecture.md §8.12 records the
 * same thing in prose.
 *
 * ### Reserved names
 *
 * Two lists, and the second is the one that matters. {@see RESERVED} is the
 * conventional set — `www`, `admin`, `api` and friends — which exists because
 * those are the names a platform will want later and cannot take back once a
 * customer holds one.
 *
 * The second list is computed from `app.system_hosts` and the control-plane
 * host, and it is a boundary rather than a convention. [XIV-57] made
 * `tenant:provision` refuse to route a tenant to a system host, because a tenant
 * on the control plane's hostname is a customer being shown the platform's
 * sign-in page with nothing in any log to say why. That refusal is the last line
 * of defence and it fires far too late for self-service: by then somebody has
 * confirmed an address, been told the name is theirs, and the failure appears in
 * a provisioning run nobody is watching. So the same fact is consulted here, at
 * the moment the name is typed — and the *first label* of each system host is
 * what is reserved, because that is what actually collides. A control plane at
 * `control.xivi.app` is reached by a signup for the slug `control` under the
 * same domain; reserving the string `control.xivi.app` would have protected
 * nothing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SelfServiceSlug
{
    /**
     * One DNS label, as RFC 1123 allows it.
     *
     * Read the class docblock before touching this, and before touching
     * {@see TenantProvisioner::SLUG_PATTERN}: the two differ on purpose.
     */
    public const string PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /** The longest a DNS label may be, and also `tenant.slug`'s column width. */
    public const int MAX_LENGTH = 63;

    /**
     * Names a platform needs to keep for itself.
     *
     * Conventional rather than derived, so it is written out: these are the
     * hostnames somebody will want for a status page, a documentation site or a
     * support desk, and the cost of reserving one that is never used is nothing
     * while the cost of having sold one is a customer being asked to rename.
     */
    private const array RESERVED = [
        'www', 'admin', 'api', 'mail', 'app', 'control', 'status', 'support',
    ];

    /** @var list<string> reserved names computed from this deployment's own hosts */
    private array $deploymentReserved;

    /**
     * @param list<string> $systemHosts      `app.system_hosts` — every host this installation
     *                                       serves without resolving a tenant, the control
     *                                       plane's included
     * @param string       $controlPlaneHost named separately as well as being in the list
     *                                       above, because the acceptance criterion names it
     *                                       separately and because a deployment that ever
     *                                       decouples the two must not silently drop it here
     */
    public function __construct(
        #[Autowire('%app.system_hosts%')]
        array $systemHosts = [],
        #[Autowire('%app.control_plane_host%')]
        string $controlPlaneHost = '',
    ) {
        $reserved = [];

        foreach ([...$systemHosts, $controlPlaneHost] as $host) {
            $host = TenantResolver::normalize($host);

            if ($host === '') {
                continue;
            }

            // Both the whole name and its first label. The first label is what a
            // signup can actually collide with — `control` under the same domain
            // as `control.xivi.app` — and the whole name covers the single-label
            // hosts a stack has anyway (`localhost`, `php`), which are exactly as
            // unavailable. Anything that is not a legal slug in the first place
            // (`127.0.0.1`, `[::1]`) falls out here rather than being carried as
            // noise nobody can type.
            foreach ([$host, strstr($host, '.', true) ?: $host] as $candidate) {
                if (self::matchesPattern($candidate)) {
                    $reserved[] = $candidate;
                }
            }
        }

        $this->deploymentReserved = array_values(array_unique($reserved));
    }

    /**
     * The name a company gets by default, derived from what they call
     * themselves.
     *
     * **Derivation is part of the contract rather than the form's business**
     * ([XIV-65] draws the form; this decides what it shows). Two implementations
     * of a rule like this — one in a page's JavaScript and one on the server —
     * differ on the first accented character somebody types, and the way you find
     * out is a customer being told their suggested name is invalid the moment
     * they submit it.
     *
     * Transliteration is locale-aware, which is not a detail here: `Bäckerei` is
     * `baeckerei` to a German reader and `backerei` to Symfony's default rules,
     * and the German answer is the one a German company expects to see. The
     * caller passes the language the visitor is reading the form in.
     *
     * Returns an empty string when nothing usable is left — a company name made
     * entirely of characters that do not transliterate. That is a refusal
     * (`invalid_slug`) rather than an invented name, because a suggestion nobody
     * recognises is worse than being asked to type one.
     */
    public function derive(string $companyName, string $locale = 'en'): string
    {
        $slug = new AsciiSlugger($locale)->slug($companyName, '-')->lower()->toString();

        // The slugger has already done the transliteration and the separator;
        // this is the part that makes the result a *DNS label* rather than a
        // pretty URL segment. Anything it did not fold away — an underscore, a
        // plus sign, a character with no ASCII equivalent at all — becomes a
        // separator here rather than being deleted, so `A+B` reads as `a-b`
        // rather than `ab`.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if (\strlen($slug) > self::MAX_LENGTH) {
            // Cut, then trim again: a cut that lands on a hyphen would leave a
            // trailing one, which is the one thing the pattern forbids at the
            // end.
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return $slug;
    }

    /** Whether this is a legal self-service slug, saying nothing about whether it is free. */
    public function isValid(string $slug): bool
    {
        return self::matchesPattern($slug);
    }

    /**
     * Whether this installation keeps the name for itself.
     *
     * Separate from "somebody else has it" in this class and deliberately *not*
     * separate in what the endpoint answers — see {@see SignupError::SlugTaken}
     * for why one word comes back for both.
     */
    public function isReserved(string $slug): bool
    {
        return \in_array($slug, self::RESERVED, true)
            || \in_array($slug, $this->deploymentReserved, true);
    }

    /**
     * Every name this deployment holds, for the test that proves the
     * control-plane host is among them.
     *
     * @return list<string>
     */
    public function reservedNames(): array
    {
        return [...self::RESERVED, ...$this->deploymentReserved];
    }

    private static function matchesPattern(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) === 1;
    }
}

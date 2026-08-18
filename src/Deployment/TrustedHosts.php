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

namespace App\Deployment;

use App\Tenancy\TenantResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which hostnames this installation answers to (XIV-93, docs/architecture.md
 * §4.3).
 *
 * ## What was unset, and what actually followed from it
 *
 * `framework.trusted_hosts` was not configured at all, so the `Host` header was
 * taken exactly as sent. Tenancy blocks most of what that would otherwise allow:
 * `TenantRequestListener` resolves the host through the registry and throws
 * `NotFoundHttpException` for a host no tenant claims, so an arbitrary
 * `Host: evil.example` does not reach a tenant page. That is a real mitigation
 * and it is why this had not bitten.
 *
 * **The residue is the hosts that deliberately resolve no tenant.**
 * `app.system_hosts` bypasses tenant resolution by design and, since [XIV-57],
 * the control-plane hostname is one of them. So anybody who can set `Host:` to
 * the control-plane hostname reaches the control-plane login page from any
 * address that terminates the connection. **This class does not change that, and
 * cannot** — the control-plane host is by definition one of the hostnames this
 * installation answers to, so it is inside the pattern rather than outside it.
 * What makes the control plane isolated is §8.9's three layers, not its
 * hostname; §4.3 and §8.9 say so in as many words, because "no tenant hostname
 * can reach a control-plane route" reads like a stronger guarantee than it is.
 *
 * The half that *is* fixed here is generated links. Absolute URLs built during a
 * web request take their host from the request, and invitations ([XIV-1]) go out
 * as Symfony login links — absolute URLs in an email. `routing.default_uri`
 * covers a console command and does not cover this, because a request context
 * wins over it. A trusted-host pattern is what stops a host this installation
 * does not serve from ever becoming the host in a link somebody is invited to
 * click.
 *
 * ## Why the pattern is composed here rather than written in `.env`
 *
 * `trusted_hosts` is a list of **regular expressions**, and this application's
 * hostnames are a wildcard by design: every customer gets their own (§4). So the
 * pattern has to admit `*.<deployment domain>` plus the control-plane host plus
 * whatever else the deployment serves — and the two ways of getting that wrong
 * are not symmetrical. A pattern that admits too much is the same as not setting
 * one; a pattern that admits too little **takes a paying customer's installation
 * off the air**, and the symptom is a bare 400 with nothing in it.
 *
 * Asking a deployment for a regular expression puts that asymmetry in the hands
 * of whoever is editing an environment file at the time. Two failures are one
 * keystroke away and neither announces itself: an unanchored `xivi\.app` also
 * matches `xivi.app.evil.example`, and a forgotten backslash makes every dot a
 * wildcard — which is the exact mistake §8.9 already refused to make when it
 * declined to host-scope the control-plane firewall with Symfony's `host:` key.
 *
 * **So a deployment names domains and this class writes the regexes.**
 * `XIVI_TRUSTED_DOMAINS=xivi.app,1plc.ch` says "customers of this installation
 * live under these names", which is a fact an operator knows, and every pattern
 * produced from it is anchored at both ends by construction.
 *
 * ## The system hosts are added rather than asked for
 *
 * Every entry of `app.system_hosts` is admitted as an exact literal, and that is
 * the property worth stating: the control-plane host, the signup host and the
 * container's own names cannot be left out of the pattern by a deployment that
 * only remembers to list its customer domain. It is the same construction
 * §8.9 uses to keep `CONTROL_PLANE_HOST` and `app.system_hosts` in step — one
 * fact, composed, rather than two things somebody has to keep equal — and it
 * matters most for the control plane, which §8.9 asks to be served on a name
 * that is *not* guessable from the customer-facing domain and therefore often is
 * not under it either.
 *
 * `localhost`, `127.0.0.1`, `[::1]` and `php` are in that list too and are
 * admitted with everything else. They are already served without a tenant, so
 * trusting them grants nothing new, and refusing them would break the container
 * health check and the internal name Gotenberg is reached on — which is the
 * too-narrow failure arriving on the first deploy rather than on the first
 * customer.
 *
 * ## Nothing configured means nothing changes
 *
 * With `XIVI_TRUSTED_DOMAINS` empty — the shipped default — {@see patterns()}
 * is empty and `Request::setTrustedHosts()` is never called, so a fresh
 * checkout, `bin/ci` and `bin/compose up` behave exactly as they did before this
 * existed. That is deliberate and is not an oversight: development serves
 * `*.localhost`, the suite invents hostnames per test, and a pattern that had to
 * be maintained for either of them would be a pattern maintained for the case
 * that does not matter.
 *
 * The system hosts are **not** turned into patterns on their own when no domain
 * is configured, and that is the subtle half. A non-empty pattern list switches
 * host checking on for everybody, so a list holding only `localhost` and the
 * control plane would refuse every tenant this installation has — a class that
 * silently took an installation dark by being installed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TrustedHosts
{
    /** The one thing a deployment sets, named here so every message can say it. */
    public const string VARIABLE = 'XIVI_TRUSTED_DOMAINS';

    /**
     * One DNS name, as RFC 1123 allows it: labels of letters, digits and
     * hyphens, not starting or ending with a hyphen, up to 63 characters each.
     *
     * A single label is allowed on purpose. `XIVI_TRUSTED_DOMAINS=localhost`
     * admits `localhost` and `acme.localhost`, which is exactly what a
     * development or staging deployment wants and is what makes this testable
     * against the compose stack rather than only in a unit test.
     */
    private const string DOMAIN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /** @var list<string> the deployment's own domains, normalised */
    private array $domains;

    /** @var list<string> `app.system_hosts`, normalised, without the empty entries */
    private array $systemHosts;

    /**
     * @param string       $domains     `XIVI_TRUSTED_DOMAINS`, comma separated. Each entry
     *                                  admits the domain itself and every subdomain of it
     * @param list<string> $systemHosts `app.system_hosts` — the hosts this installation
     *                                  serves without resolving a tenant
     *
     * @throws \InvalidArgumentException when an entry is not a hostname at all, which is a
     *                                   configuration error rather than a narrow pattern and
     *                                   is therefore better refused than silently compiled
     *                                   into a regex that matches nothing
     */
    public function __construct(
        #[Autowire('%env(string:default::XIVI_TRUSTED_DOMAINS)%')]
        string $domains = '',
        #[Autowire('%app.system_hosts%')]
        array $systemHosts = [],
    ) {
        $parsed = [];

        foreach (explode(',', $domains) as $entry) {
            // Leading `*.` and a leading or trailing dot are all things somebody
            // writes when they are thinking about DNS rather than about this
            // variable, and every one of them means what this variable already
            // means. Accepting them costs three calls and saves an operator from
            // an installation that starts and serves nobody.
            $entry = ltrim(strtolower(trim($entry)), '*');
            $entry = trim($entry, '.');

            if ($entry === '') {
                // A trailing comma, or the empty default. Neither is a mistake
                // worth a refusal.
                continue;
            }

            if (preg_match(self::DOMAIN, $entry) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    '%s contains "%s", which is not a hostname. It is a comma-separated list of '
                    . 'the domains this installation serves customers under (xivi.app, 1plc.ch) — '
                    . 'not URLs, not regular expressions, and not patterns: each entry already '
                    . 'admits the domain itself and every name under it.',
                    self::VARIABLE,
                    $entry,
                ));
            }

            $parsed[] = $entry;
        }

        $this->domains = array_values(array_unique($parsed));

        $hosts = [];
        foreach ($systemHosts as $host) {
            $host = TenantResolver::normalize((string) $host);

            // Empty is the ordinary state of `%app.signup_host%` — signup is off
            // by default and `app.system_hosts` carries the empty entry rather
            // than building a conditional list. No request has an empty `Host`,
            // so it matches nothing there and has nothing to admit here.
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        $this->systemHosts = array_values(array_unique($hosts));
    }

    /** Whether this deployment has said which hostnames it answers to at all. */
    public function isConfigured(): bool
    {
        return $this->domains !== [];
    }

    /**
     * The domains a deployment named, in the order it named them.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return $this->domains;
    }

    /**
     * The hosts admitted by construction rather than because somebody listed
     * them — `app.system_hosts`, the control plane's own name among them.
     *
     * @return list<string>
     */
    public function alwaysAdmitted(): array
    {
        return $this->systemHosts;
    }

    /**
     * The regular expressions `framework.trusted_hosts` is given, or none.
     *
     * Each is anchored at both ends. The optional trailing dot is not decoration:
     * `acme.xivi.app.` is a fully qualified name, browsers and command-line
     * clients both send it, `Request::getHost()` does **not** strip the dot
     * before matching — it only lowercases and removes the port — and
     * `TenantResolver::normalize()` *does* strip it before looking a tenant up.
     * So a pattern without `\.?` would 400 a request that resolves perfectly well
     * today, which is precisely the class of narrowing this whole class exists to
     * avoid.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $patterns = [];

        foreach ($this->systemHosts as $host) {
            $patterns[] = '^' . preg_quote($host) . '\.?$';
        }

        foreach ($this->domains as $domain) {
            // `(?:[a-z0-9_-]+\.)*` rather than `.*\.?`: any number of labels,
            // each made only of what a label may contain. The underscore is
            // there because it is legal in a hostname a service discovery layer
            // hands out, and because `Request::isHostValid()` accepts it — a
            // pattern stricter than the framework's own validity check would
            // refuse names that reach this application through no fault of
            // anybody's.
            $patterns[] = '^(?:[a-z0-9_-]+\.)*' . preg_quote($domain) . '\.?$';
        }

        return $patterns;
    }

    /**
     * The same list as one string, which is the shape `framework.trusted_hosts`
     * takes it in (see {@see TrustedHostPatterns}).
     *
     * Empty when nothing is configured, and empty is what `Kernel::preBoot()`
     * treats as "no trusted hosts" — so the fallback for an unconfigured
     * deployment is the framework's own, not a value of ours that happens to
     * behave like it.
     */
    public function pattern(): string
    {
        // Commas cannot appear in any pattern this class writes — a hostname has
        // none and `preg_quote()` introduces none — so joining and splitting on
        // one round-trips. `Kernel::preBoot()` does the splitting.
        return implode(',', $this->patterns());
    }

    /**
     * Whether a request for this hostname would be served or refused with a 400.
     *
     * **Matched exactly as the framework matches it**, wrapped in the same `{}i`
     * delimiters `Request::setTrustedHosts()` uses, so that what this answers and
     * what an incoming request gets cannot disagree. A second implementation of
     * the comparison is how a check comes to pass while the thing it checks
     * fails.
     *
     * An unconfigured deployment admits everything, and this says so rather than
     * refusing: that is the truth about such an installation, and answering
     * anything else would make `deploy:check-hosts` report a problem that does
     * not exist.
     */
    public function admits(string $host): bool
    {
        $patterns = $this->patterns();

        if ($patterns === []) {
            return true;
        }

        // Lowercased and stripped of its port, which is what `Request::getHost()`
        // does to a header before it consults the patterns. The trailing dot is
        // deliberately left alone for the same reason — the framework leaves it
        // alone too, and the patterns above account for it.
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        if ($host === '') {
            // No request has an empty `Host`, and the anchored patterns below
            // would refuse it anyway. Answered explicitly so that a caller
            // passing an empty registry row gets a refusal rather than a
            // regular-expression accident.
            return false;
        }

        foreach ($patterns as $pattern) {
            if (preg_match(sprintf('{%s}i', $pattern), $host) === 1) {
                return true;
            }
        }

        return false;
    }
}

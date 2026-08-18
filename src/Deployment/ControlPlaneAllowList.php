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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which addresses may reach the control plane at all (XIV-124,
 * docs/architecture.md §8.9).
 *
 * ## What this is the answer to, and what it deliberately is not
 *
 * §8.9 counts three layers between a customer and an operator's console, and
 * [XIV-93] added a sentence to the top of that list which is easy to read past:
 * **the hostname is not one of them**. Anybody who can set `Host:` to the
 * control-plane name reaches its sign-in page from any address that terminates
 * the connection, and no trusted-host pattern can change that, because the
 * control-plane host is by construction one of the names this installation
 * answers to (§4.3 has the whole argument).
 *
 * Every one of the three layers that *does* hold — the route existing on no
 * other host, the credential being answerable only by the `operators` provider,
 * `access_control` demanding a `ROLE_OPERATOR` no customer's database can grant
 * — is a check made **after the request has arrived**, by the surface that can
 * see every customer. That is not a criticism of them; they are the layers that
 * decide who gets in, and they are correct. It is an observation about what is
 * missing, which is anything at all in *front*.
 *
 * This is that thing, and it is the outermost of four rather than a replacement
 * for any of the other three. Nothing here weakens the firewall ordering §8.9
 * calls the security boundary, nothing here changes which provider answers a
 * credential, and `ControlPlaneFirewallTest` is untouched. As the only layer
 * this would be bad design — an address is a claim about the network, and
 * networks are borrowed, spoofed on unfiltered paths and shared by everyone
 * behind one office NAT. As the fourth it is worth having: it turns "anybody on
 * the internet may attempt a password" into "anybody on this list may attempt a
 * password", which is the difference between an exposed login form and one that
 * is merely reachable by people who are already somewhere.
 *
 * ## Optional, and empty means exactly what it did before
 *
 * With `CONTROL_PLANE_ALLOWED_IPS` empty — the shipped default — {@see
 * isConfigured()} is false, the listener returns before it looks at anything,
 * and an installation behaves precisely as it did before this class existed.
 * That is the same rule {@see PlaceholderSecretGuard} and {@see TrustedHosts}
 * follow, for the same reason: `bin/compose up`, `bin/ci` and the suite all run
 * on hostnames and addresses that no operator would ever write down, and a
 * setting that had to be maintained for them would be a setting maintained for
 * the case that does not matter.
 *
 * ## Where the address comes from, which is the whole trap
 *
 * **Never from a header this class reads.** The client's address is resolved by
 * `Request::getClientIp()`, which consults `X-Forwarded-For` **only** from an
 * address listed in `TRUSTED_PROXIES` and only because `x-forwarded-for` is one
 * of the headers §4.3 decided to believe. So this allow-list inherits [XIV-93]'s
 * configuration rather than acquiring a second, quieter copy of it.
 *
 * The alternative — reading `X-Forwarded-For` here — is not a smaller version of
 * the same thing. It is **worse than having no allow-list at all**, because an
 * allow-list built on a header anybody can set admits anybody who reads this
 * file, while looking exactly like a restriction to whoever configured it. The
 * shipped topology (§4) has FrankenPHP terminating TLS with nothing in front of
 * it, so `TRUSTED_PROXIES` is empty, so `X-Forwarded-For` is ignored entirely
 * and `getClientIp()` is the socket peer. `ControlPlaneAllowListTest` sends a
 * forged header and proves it buys nothing.
 *
 * ## Ranges, because an office is a range
 *
 * Entries are addresses or CIDR blocks and are matched with
 * `IpUtils::checkIp()`, which is Symfony's own — the same function `TRUSTED_PROXIES`
 * itself is matched with. IPv4 and IPv6 both work, and a mixed list is fine:
 * `checkIp()` picks its comparison from the family of the address being asked
 * about, so an IPv4 entry simply never matches an IPv6 caller rather than
 * matching one by accident. A string comparison would have handled neither the
 * ranges nor `::1` being the same address as `0:0:0:0:0:0:0:1`.
 *
 * ## An entry that is not an address does not switch the restriction off
 *
 * This is the one judgement call in the class and it is deliberately the
 * unhelpful-looking one. A typo'd entry is **dropped and remembered** (see
 * {@see rejected()}): the list stays *configured*, so the restriction stays on,
 * and the entry admits nobody. A list whose every entry is a typo therefore
 * admits nobody at all.
 *
 * The two alternatives are both worse. **Throwing** would be consistent with
 * {@see TrustedHosts}, and is wrong here because the blast radius is not the
 * same: this service is constructed on the request path, so an exception in it
 * is a 500 for every customer of the installation over one mistyped character in
 * a variable that is about the operator's own console. §4.3's asymmetry
 * argument, applied one step along. **Treating an all-invalid list as
 * unconfigured** is the failure this whole file is about: a restriction that
 * silently stops restricting, believed in by the person who set it.
 *
 * So the cost lands on the operator who made the mistake and on nobody else, and
 * `deploy:check-control-plane` exists so that it can be found before it lands at
 * all. The honest statement of the residual risk is in §8.9 and it is repeated
 * here because it is the reason this class has a check command: **an operator
 * who never runs it can still lock themselves out, and the symptom is a 403 on
 * the console rather than anything a customer would report.**
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ControlPlaneAllowList
{
    /** The one thing a deployment sets, named here so every message can say it. */
    public const string VARIABLE = 'CONTROL_PLANE_ALLOWED_IPS';

    /** @var list<string> entries that are an address or a CIDR block */
    private array $allowed;

    /** @var list<string> entries that are neither, kept so somebody can be told */
    private array $rejected;

    /** Whether the deployment wrote anything at all, valid or not. */
    private bool $configured;

    /**
     * @param string $allowed `CONTROL_PLANE_ALLOWED_IPS`, comma separated: addresses,
     *                        CIDR blocks, or a mixture, in either address family
     */
    public function __construct(
        #[Autowire('%env(string:default::CONTROL_PLANE_ALLOWED_IPS)%')]
        string $allowed = '',
    ) {
        $valid = [];
        $rejected = [];
        $seen = false;

        foreach (explode(',', $allowed) as $entry) {
            // Whitespace around a comma is what somebody writes when they are
            // laying the list out to be read, and a deployment variable that
            // punished them for it would be punishing legibility.
            $entry = trim($entry);

            if ($entry === '') {
                // A trailing comma, or the empty default. Neither says anything
                // about whether the deployment meant to configure this.
                continue;
            }

            $seen = true;

            if (self::isAddressOrRange($entry)) {
                $valid[] = $entry;
            } else {
                $rejected[] = $entry;
            }
        }

        $this->allowed = array_values(array_unique($valid));
        $this->rejected = array_values(array_unique($rejected));
        $this->configured = $seen;
    }

    /**
     * Whether this deployment restricts the control plane by address at all.
     *
     * True as soon as the variable holds anything, **including when every entry
     * in it was rejected**. See the class docblock: a list that is entirely
     * typos is a list that admits nobody, not a list that is switched off.
     */
    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * The entries that will actually be matched against, in the order they were
     * written.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->allowed;
    }

    /**
     * The entries that are not an address or a CIDR block, and therefore admit
     * nobody.
     *
     * Exposed rather than logged from the constructor because the constructor
     * has no idea who is listening: this is read by
     * `App\Command\CheckControlPlaneAccessCommand`, which prints it to an
     * operator, and by the listener, which puts it in the one log line a refused
     * request produces. The same words in both places, and no guess here about
     * which of them exists.
     *
     * @return list<string>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /**
     * Whether a request from this address may reach the control plane.
     *
     * **`null` is a refusal, not a pass.** `Request::getClientIp()` returns null
     * when there is no `REMOTE_ADDR` to resolve — a request built in a test, or
     * one arriving over a transport that has no peer address. An installation
     * that has said which addresses may reach its console has not said "and also
     * anything I cannot identify", and "cannot tell" is not an answer to resolve
     * in favour of admitting; it is the call `PlaceholderSecretGuard` makes about
     * an unreadable `.env` (§4.2).
     *
     * An unconfigured deployment admits everything and says so plainly, which is
     * the truth about such an installation rather than a special case: answering
     * anything else would make `deploy:check-control-plane` report a restriction
     * that does not exist.
     */
    public function admits(?string $address): bool
    {
        if (!$this->configured) {
            return true;
        }

        if ($address === null || $address === '' || filter_var($address, \FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($this->allowed === []) {
            // Configured, and every entry was a typo. `IpUtils::checkIp()` with
            // an empty list already returns false; this is written out so that
            // the behaviour is a decision somebody made rather than a property
            // of a loop that happens not to run.
            return false;
        }

        return IpUtils::checkIp($address, $this->allowed);
    }

    /**
     * Whether an entry is something `IpUtils` can match against.
     *
     * Validated here rather than left to `IpUtils::checkIp()` — which is
     * forgiving and simply never matches a malformed entry — because "never
     * matches" is indistinguishable from "the caller is not on the list", and
     * the whole point of {@see rejected()} is to be able to tell those apart.
     *
     * The prefix length is checked against the family of the address it belongs
     * to, so `10.0.0.0/64` is refused. That is not pedantry: `IpUtils::checkIp4()`
     * caps a prefix above 32 at 32, which turns `10.0.0.0/64` into the single
     * host `10.0.0.0` — an entry that looks like a range, admits one address,
     * and reports nothing.
     */
    private static function isAddressOrRange(string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return filter_var($entry, \FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if (filter_var($address, \FILTER_VALIDATE_IP) === false || !ctype_digit($prefix)) {
            return false;
        }

        // An IPv6 address is the one with more than one colon; a single colon
        // would be a port, which is not something an entry here may carry and
        // which `filter_var()` has already refused above.
        return (int) $prefix <= (substr_count($address, ':') > 1 ? 128 : 32);
    }
}

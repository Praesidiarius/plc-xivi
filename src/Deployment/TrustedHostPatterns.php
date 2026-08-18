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
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * How {@see TrustedHosts} reaches `framework.trusted_hosts` (XIV-93,
 * docs/architecture.md §4.3).
 *
 * ## Why an environment-variable processor and not something simpler
 *
 * `framework.trusted_hosts` is a container **parameter**, read once by
 * `Kernel::preBoot()` and handed to `Request::setTrustedHosts()`. Parameters do
 * not compute, and the value this application needs is a computation: the
 * deployment's domains, turned into anchored regular expressions, unioned with
 * every entry of `app.system_hosts`.
 *
 * The three obvious homes for that computation are all worse:
 *
 * - **A literal in `config/packages/framework.yaml`** is the thing XIV-93 exists
 *   to avoid. The hostnames are per deployment and one of them is a wildcard.
 * - **A compiler pass** runs at build time, and the values it would need are
 *   environment variables that do not exist then — the production image is built
 *   without any deployment's `CONTROL_PLANE_HOST`, deliberately (§4.2).
 * - **`Kernel::boot()`** would work and would put a security property in an
 *   override of a framework method, reachable by nothing that can test it
 *   without booting a kernel.
 *
 * An env-var processor is the framework's own seam for exactly this: a value
 * that is not known until the process starts, resolved lazily, out of a service
 * the container can build and a test can construct by hand.
 *
 * ## Why it hands back a string
 *
 * `getProvidedTypes()` says `string` rather than `array` because the
 * configuration node behind `framework.trusted_hosts` is a prototype of scalars,
 * and a placeholder declared as an array in a scalar node is refused while the
 * container is compiled. A comma-separated string is also the shape Symfony's
 * own `SYMFONY_TRUSTED_HOSTS` takes, and `Kernel::preBoot()` splits it back
 * apart. {@see TrustedHosts::pattern()} owns the joining, and says there why
 * the round trip is safe.
 *
 * The empty string is the important return value: `preBoot()` treats a falsy
 * parameter as "no trusted hosts configured" and never calls
 * `setTrustedHosts()` at all. So a deployment that sets nothing gets the
 * framework's own behaviour rather than ours imitating it.
 *
 * ## Why `app.system_hosts` is injected rather than read from the environment
 *
 * The list is `CONTROL_PLANE_HOST` and `SIGNUP_HOST` composed with four literal
 * infrastructure names, and it already has three readers (§8.9). Reassembling it
 * from its two variables here would be a fourth definition of it, and the way
 * anybody would find out it had drifted is a control-plane host that 400s after
 * somebody added a fifth entry to the parameter.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TrustedHostPatterns implements EnvVarProcessorInterface
{
    /**
     * @param list<string> $systemHosts
     */
    public function __construct(
        #[Autowire('%app.system_hosts%')]
        private array $systemHosts = [],
    ) {
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $domains = $getEnv($name);

        return (new TrustedHosts(\is_string($domains) ? $domains : '', $this->systemHosts))->pattern();
    }

    /**
     * @return array<string, string>
     */
    public static function getProvidedTypes(): array
    {
        return ['xivi_trusted_hosts' => 'string'];
    }
}

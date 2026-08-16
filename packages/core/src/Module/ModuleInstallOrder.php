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

namespace Xivi\Core\Module;

/**
 * The order a set of modules has to be installed in, read out of the blueprints'
 * own `requires` rather than out of whoever typed the list (XIV-72).
 *
 * `ModuleInstaller` already refuses a module whose requirements the tenant has
 * not got (XIV-23), which is the right refusal for one install and the wrong
 * failure for a batch: asking for contact, article, order and invoice in the
 * order somebody happened to think of them fails halfway through, leaving a
 * tenant with two modules and no way to tell whether that was the intent. The
 * knowledge needed to avoid it — that an invoice needs an order and an order
 * needs a contact — is written down in the blueprints already, so nothing is
 * gained by making a developer carry it in their head as well.
 *
 * A depth-first walk rather than Kahn's algorithm, for one property that matters
 * more here than asymptotics: the recursion *is* the explanation. When it finds
 * a cycle it is standing inside it and can name the whole path, which is the
 * only useful thing to say about one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleInstallOrder
{
    public function __construct(private ModuleRegistry $modules)
    {
    }

    /**
     * The same modules, ordered so that nothing is installed before what it needs.
     *
     * Requirements outside `$keys` are **not** pulled in: a caller that asked for
     * four modules and got six installed was not obeyed, and the whole point of
     * this class is that the caller's list is what gets installed and only the
     * order is taken out of their hands. Use {@see closureOf()} to find out what
     * the list should have been, and say so, before touching anything.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException if a key names no module in this build,
     *                                   if a requirement is missing from `$keys`,
     *                                   or if the requirements are circular
     */
    public function of(array $keys): array
    {
        return $this->sort($keys, within: $keys);
    }

    /**
     * The same modules plus everything they need, ordered.
     *
     * What a caller's list *should* have said. Used to turn "invoice needs order"
     * into a corrected command line somebody can paste, which is a better error
     * than the name of the one module that was missing.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException if a key names no module in this build,
     *                                   or if the requirements are circular
     */
    public function closureOf(array $keys): array
    {
        return $this->sort($keys, within: null);
    }

    /**
     * @param list<string>      $keys
     * @param list<string>|null $within the set requirements must come from; null
     *                                  allows any module in the build
     *
     * @return list<string>
     */
    private function sort(array $keys, ?array $within): array
    {
        $ordered = [];
        $done = [];

        foreach ($keys as $key) {
            $this->visit($key, $within, $ordered, $done, path: []);
        }

        return $ordered;
    }

    /**
     * @param list<string>|null   $within
     * @param list<string>        $ordered
     * @param array<string, true> $done
     * @param list<string>        $path    the chain that led here, so a cycle can name itself
     *
     * @param-out list<string>        $ordered
     * @param-out array<string, true> $done
     */
    private function visit(string $key, ?array $within, array &$ordered, array &$done, array $path): void
    {
        if (isset($done[$key])) {
            return;
        }

        if (\in_array($key, $path, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Modules require each other in a circle: %s. One of those declarations is wrong; '
                . 'no install order exists.',
                implode(' → ', [...$path, $key]),
            ));
        }

        // Throws for a module this build does not carry, with the list of the
        // ones it does — the same refusal, worded the same way, whether the key
        // came from the caller or from another module's `requires`.
        $blueprint = $this->modules->get($key);

        foreach ($blueprint->requires as $requirement) {
            if ($within !== null && !\in_array($requirement, $within, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Module "%s" needs "%s", which is not in the requested set.',
                    $key,
                    $requirement,
                ));
            }

            $this->visit($requirement, $within, $ordered, $done, [...$path, $key]);
        }

        $done[$key] = true;
        $ordered[] = $key;
    }
}

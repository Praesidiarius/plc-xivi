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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every module the deployed code knows how to install, keyed by module key.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleBlueprint> */
    private array $blueprints = [];

    /** @param iterable<ModuleProvider> $providers */
    public function __construct(
        #[AutowireIterator(ModuleProvider::TAG)]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $blueprint = $provider->blueprint();
            $this->blueprints[$blueprint->key] = $blueprint;
        }
    }

    public function get(string $key): ModuleBlueprint
    {
        return $this->blueprints[$key] ?? throw new \InvalidArgumentException(sprintf(
            'No module "%s" is available in this build. Known modules: %s.',
            $key,
            $this->blueprints === [] ? 'none' : implode(', ', array_keys($this->blueprints)),
        ));
    }

    public function has(string $key): bool
    {
        return isset($this->blueprints[$key]);
    }

    /** @return array<string, ModuleBlueprint> */
    public function all(): array
    {
        return $this->blueprints;
    }
}

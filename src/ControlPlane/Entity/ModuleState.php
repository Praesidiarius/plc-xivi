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

namespace App\ControlPlane\Entity;

/**
 * How far along a module is, platform-wide (XIV-7).
 *
 * Deliberately not per tenant: whether a module is finished is a fact about the
 * module, and a customer being shown a half-built one because somebody flipped a
 * row on their tenant is exactly the kind of drift §4 rejects.
 *
 * A closed set, extended by adding a case — `early_access` is the obvious next
 * one. Nothing matches on the case list except the helpers below, so a new state
 * has one place to declare what it means and PHP refuses the change until it does.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum ModuleState: string
{
    /** Exists in the build, not offered to anybody. Where every module starts. */
    case Development = 'development';

    /** Finished, and offered to every tenant. */
    case Published = 'published';

    /**
     * Whether the store (XIV-6) lists it.
     *
     * The state's whole point, and the only question anything asks of it today.
     * Installing is deliberately not gated by it: a module is developed by
     * installing it somewhere, which is the case the state exists to describe.
     */
    public function isOfferedInStore(): bool
    {
        return match ($this) {
            self::Published => true,
            self::Development => false,
        };
    }
}

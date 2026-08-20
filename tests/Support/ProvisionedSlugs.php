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

namespace App\Tests\Support;

/**
 * Every tenant slug `SharesATenant` has provisioned in this process. Really in
 * this process, which is the whole reason this is a class (XIV-148).
 *
 * The bookkeeping used to be a static property on the trait itself, and that
 * was the bug: PHP gives every class that uses a trait its **own copy** of the
 * trait's static properties, so a set meant to answer "has anybody in this
 * process claimed this slug?" was only ever asked "has *this class* claimed
 * it?". The guard built on it never fired. That guard says: reuse a slug a
 * previous class already provisioned, because deprovisioning it would
 * terminate the DAMA connection that class is still holding ([XIV-94]). So the
 * second class to share a slug tore the tenant down under the first one's
 * feet. A static on an ordinary class has exactly one slot per process, which
 * is what the sentence "provisioned in this process" needs to be true.
 *
 * Never reset, deliberately. The set exists to describe the process, and a
 * process cannot un-provision the past: the databases stay standing for the
 * whole run (see SharesATenant on why), so forgetting one here would only
 * reintroduce the deprovision this class exists to prevent.
 *
 * @author Nathanael Kammermann <nathanael.kammermann@gmail.com>
 */
final class ProvisionedSlugs
{
    /** @var array<string, true> */
    private static array $slugs = [];

    public static function has(string $slug): bool
    {
        return isset(self::$slugs[$slug]);
    }

    public static function add(string $slug): void
    {
        self::$slugs[$slug] = true;
    }
}

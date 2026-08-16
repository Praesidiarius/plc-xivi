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

namespace App\ControlPlane\Introspection;

/**
 * A slug nobody has provisioned.
 *
 * It names the slugs that *do* exist, and that is the whole reason it is a class
 * rather than a bare `\InvalidArgumentException`. The two readers of this message
 * are a developer who has mistyped a slug and an agent that has guessed one, and
 * for both of them the correction is the list — an agent told only "no such
 * tenant" will guess again, which is how a session turns into three wrong calls
 * instead of one wrong call and a right one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UnknownTenant extends \InvalidArgumentException
{
    /** @param list<string> $known */
    public static function named(string $slug, array $known): self
    {
        return new self(sprintf(
            'No tenant with slug "%s". Provisioned: %s.',
            $slug,
            implode(', ', $known) ?: 'none',
        ));
    }
}

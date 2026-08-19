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

namespace Xivi\Core\ValueList;

/**
 * No shared list of that key exists in this tenant's database (XIV-127).
 *
 * {@see \Xivi\Core\Metadata\ModuleNotInstalled}'s twin, and separate from it for
 * the reason that one is separate from
 * {@see \Xivi\Core\Metadata\MetadataChangeRefused}: this is a URL naming
 * something that is not there, which is a 404 and not a sentence a customer has
 * to act on. A field *pointing* at a list that has since gone is a different
 * matter and is deliberately not this — that field keeps working, showing the
 * values records hold, because a page that threw would take a module's whole
 * record list down over a picker (§5.4's rule that a value renders even when its
 * option has gone).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ValueListNotFound extends \RuntimeException
{
    public static function named(string $key): self
    {
        return new self(sprintf('No shared list named "%s" exists for this tenant.', $key));
    }
}

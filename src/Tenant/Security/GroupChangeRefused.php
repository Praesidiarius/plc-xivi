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

namespace App\Tenant\Security;

/**
 * A change to a permission group that the application will not make.
 *
 * Separate from UserChangeRefused, which is about lock-out: none of these can
 * strand an administrator, because ROLE_ADMIN is a bypass rather than a group
 * (§8.4.1). These are refusals about the group model staying comprehensible —
 * two groups with the same name is a screen where nobody can tell which one they
 * are editing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class GroupChangeRefused extends \RuntimeException
{
    public static function noName(): self
    {
        return new self('A group needs a name: it is what people pick it by.');
    }

    public static function nameTaken(string $label): self
    {
        return new self(sprintf('There is already a group called "%s".', $label));
    }
}

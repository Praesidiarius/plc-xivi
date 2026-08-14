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
 * States that a route under `/m/{module}` is deliberately *not* governed by one
 * of that module's permissions (§7.5).
 *
 * Every other route on that surface has to carry `#[IsGranted]` naming a
 * ModuleAction, and PermissionCoverageTest fails the build otherwise. This is the
 * way to say "not that kind of route" — and it is an attribute with a mandatory
 * reason rather than a list kept somewhere in the test, because the reason
 * belongs next to the code it excuses, where somebody changing that code will
 * read it.
 *
 * **It grants nothing and refuses nothing.** It is a statement to the check, not
 * a security control: a route carrying this still needs whatever protection is
 * actually right for it, which for the metadata editor is `ROLE_ADMIN`.
 *
 * The escape hatch exists on purpose. A check with no way out is one that gets
 * deleted the first time it is inconvenient, rather than answered.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class NoModulePermission
{
    public function __construct(
        /** Why this route is not one of a module's actions. Read by people, not by code. */
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'NoModulePermission needs a reason: the point of it is that somebody wrote down why.',
            );
        }
    }
}

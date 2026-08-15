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

use Xivi\Core\Permission\ModuleAction;

/**
 * Something grantable that is not a module (§8.4, XIV-12).
 *
 * The permission catalogue was ModuleAction crossed with the customer's installed
 * modules. The tenant profile is the first thing worth granting that no module
 * owns, so the catalogue becomes that enum crossed with modules **and** areas —
 * still worked out at runtime, still nothing seeded and nothing migrated.
 *
 * **An area is stored in `permission_grant.module_key`**, which needs no schema
 * change because that column was never a join: it is a string precisely so a
 * grant can name something the definitions do not have (§8.4). The keys begin
 * with `@`, which module keys cannot — routes require `[a-z][a-z0-9_]*` — so an
 * area can never collide with a module a customer installs, whatever they call it.
 *
 * **The verbs are ModuleAction's**, not a second enum. View and edit mean here
 * what they mean everywhere, and one vocabulary is what lets the resolver, the
 * voter and the grant table stay exactly as they are. When something wants a verb
 * this enum does not have — the store's browse and install (XIV-6) — that is the
 * moment to add a second axis, with a real second case to design it against
 * rather than a guess.
 *
 * **Scope does not apply.** There is one profile and it is nobody's own, so a
 * grant on an area is always at All and the screens offer yes or no.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum PermissionArea: string
{
    /** The instance's own settings — company name, currency (XIV-12). */
    case Profile = '@profile';

    /**
     * The actions that mean anything here, which is what the permission screens
     * draw a control for.
     *
     * @return list<ModuleAction>
     */
    public function actions(): array
    {
        return match ($this) {
            self::Profile => [ModuleAction::View, ModuleAction::Edit],
        };
    }

    public function allows(ModuleAction $action): bool
    {
        return \in_array($action, $this->actions(), true);
    }

    /** Bootstrap Icons name, without the `bi-` prefix — as a module declares one. */
    public function icon(): string
    {
        return match ($this) {
            self::Profile => 'building-gear',
        };
    }

    /** A key in the `messages` domain: an area is the application's, not a module's. */
    public function labelKey(): string
    {
        return 'permission.area.' . ltrim($this->value, '@');
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}

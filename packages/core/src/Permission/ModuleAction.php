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

namespace Xivi\Core\Permission;

/**
 * Everything that can be done to a module's records (§7.5).
 *
 * A closed enum, like the field-type registry (§5): adding an action is a
 * deliberate code change, never customer configuration. That closure is what
 * makes the permission *catalogue* free — it is this enum crossed with the
 * modules a customer has installed, worked out at runtime — so there is no table
 * of permissions to seed when a module is installed and none to migrate when a
 * new action ships. Nothing can drift out of step with the code because nothing
 * is written down twice.
 *
 * What *is* stored is grants, and only grants.
 *
 * These values are the attribute strings the security layer votes on. They are
 * deliberately not passed to `isGranted()` as enum cases: `Voter::supports()` is
 * typed `string`, and `Voter::vote()` swallows the resulting TypeError and
 * abstains — which the access decision manager reads as denied. A 403 that is
 * really a type error is the failure this codebase writes docblocks to avoid, so
 * the string is taken from here and nowhere else.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum ModuleAction: string
{
    case View = 'view';
    case List = 'list';
    case Add = 'add';
    case Edit = 'edit';
    case Delete = 'delete';
    case Export = 'export';
    case Import = 'import';

    /**
     * Whether "only the records I own" is a question this action can answer.
     *
     * Adding a record and importing a file name nothing that already exists, so
     * there is no owner to compare against. The enum saying so is what stops the
     * admin matrix from drawing a cell with no meaning, and what lets the
     * resolver refuse a grant that could never be evaluated.
     */
    public function isScopable(): bool
    {
        return match ($this) {
            self::View, self::List, self::Edit, self::Delete, self::Export => true,
            self::Add, self::Import => false,
        };
    }

    /**
     * Whether this action changes anything.
     *
     * Not used to decide access — a read can be as sensitive as a write, which is
     * why scope applies to both. It is here so the admin UI can group the columns
     * the way anyone granting them thinks about them.
     */
    public function isMutating(): bool
    {
        return match ($this) {
            self::Add, self::Edit, self::Delete, self::Import => true,
            self::View, self::List, self::Export => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'View',
            self::List => 'List',
            self::Add => 'Add',
            self::Edit => 'Edit',
            self::Delete => 'Delete',
            self::Export => 'Export',
            self::Import => 'Import',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}

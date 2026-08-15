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
     * Managing the module's document templates: uploading one, replacing it,
     * removing it (XIV-4).
     *
     * Separate from Document on purpose, and the ticket asked for it in as many
     * words: whoever designs the invoice is not whoever sends one. A template is
     * also the one upload that decides what every future document of that kind
     * looks like, which is a larger thing to hand out than the documents.
     */
    case Templates = 'templates';

    /** Generating a document for one record from one of those templates (XIV-4). */
    case Document = 'document';

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
            self::View, self::List, self::Edit, self::Delete, self::Export, self::Document => true,
            // Templates names no record: it is the module's stationery, not
            // anybody's row.
            self::Add, self::Import, self::Templates => false,
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
            self::Add, self::Edit, self::Delete, self::Import, self::Templates => true,
            // Generating a document changes nothing about the record; it is a
            // read that happens to come back as a file, like the export.
            self::View, self::List, self::Export, self::Document => false,
        };
    }

    /** A key in the `xivi` domain — see Operator::labelKey() (XIV-8). */
    public function labelKey(): string
    {
        return 'permission.action.' . $this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}

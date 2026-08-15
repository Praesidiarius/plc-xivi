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
 * How much a grant reaches: everything, or only what the person owns (§7.5).
 *
 * `Own` becomes `owner_id = :me` in the query layer rather than a check after
 * loading — §5.3's compiler reserved the slot for exactly this. That is not a
 * performance note: a filter applied after the page is fetched shows 4 rows under
 * a total that says 25, and somebody acts on the 25.
 *
 * Only meaningful for actions that name a record which already exists; see
 * ModuleAction::isScopable().
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum PermissionScope: string
{
    case Own = 'own';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own records',
            self::All => 'All records',
        };
    }

    /**
     * The wider of two grants.
     *
     * This is the whole of the resolution rule (§7.5): grants are additive and
     * nothing can deny, so combining them is a maximum rather than a precedence
     * table. Order-independent by construction, which is why "why can this person
     * still see that" never becomes a question with a complicated answer.
     */
    public function widest(self $other): self
    {
        return $this === self::All || $other === self::All ? self::All : self::Own;
    }
}

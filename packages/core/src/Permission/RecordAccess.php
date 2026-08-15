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
 * Which records one person is allowed to be shown, as something the query layer
 * can compile (§7.5).
 *
 * This is the half of the permission model a voter cannot do. A voter is handed
 * a subject and says yes or no about it, which answers "may I edit this one" and
 * cannot answer "which twenty-five am I looking at" — by the time it runs the
 * page is already fetched. §5.3's compiler reserved a slot beside the
 * soft-delete predicate for exactly this, and this is what goes in it.
 *
 * Why that matters concretely: filtering a fetched page leaves four rows under a
 * total that still says twenty-five, and somebody acts on the twenty-five.
 *
 * **Three states, not two.** "Everything", "only mine", and "nothing at all" are
 * genuinely different, and collapsing the third into a null owner id is how a
 * restriction becomes an unrestricted query by accident. Nothing-at-all exists so
 * that every way of failing to work out an answer fails closed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordAccess
{
    private function __construct(
        private bool $restricted,
        private ?int $ownerId,
    ) {
    }

    /**
     * Every record the query matches.
     *
     * Written out at the call site rather than being a default, so that a read
     * which shows everybody's records is a decision somebody made and can be
     * found by grepping for it.
     */
    public static function unrestricted(): self
    {
        return new self(false, null);
    }

    /** Only the records this person owns. */
    public static function ownedBy(int $ownerId): self
    {
        return new self(true, $ownerId);
    }

    /**
     * No records at all.
     *
     * An empty list rather than an error, because this is the second line: the
     * route should already have refused (§7.5's first seam), and a query that
     * quietly returned everything if that seam were ever wrong is not a second
     * line at all.
     */
    public static function nothing(): self
    {
        return new self(true, null);
    }

    /**
     * What a resolved permission set means for one module and one action.
     *
     * Every branch that cannot produce an owner produces nothing rather than
     * everything — including "scoped to their own records, but we do not know who
     * they are", which is the one a signed-out request would take.
     */
    public static function fromPermissions(
        PermissionSet $permissions,
        string $moduleKey,
        ModuleAction $action,
        ?int $userId,
    ): self {
        $scope = $permissions->scopeFor($moduleKey, $action);

        return match (true) {
            $scope === null => self::nothing(),
            $scope === PermissionScope::All => self::unrestricted(),
            $userId === null => self::nothing(),
            default => self::ownedBy($userId),
        };
    }

    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    /**
     * Whose records, or null when the answer is nobody's.
     *
     * Only meaningful once isRestricted() is true — unrestricted access has no
     * owner either, and the two nulls mean opposite things.
     */
    public function ownerId(): ?int
    {
        return $this->ownerId;
    }

    /** Whether this can match nothing at all, whatever the query says. */
    public function matchesNothing(): bool
    {
        return $this->restricted && $this->ownerId === null;
    }
}

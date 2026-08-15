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
 * What one person may do, resolved (§7.5).
 *
 * A value object with no idea who it belongs to. Core deliberately does not know
 * what a user is — the same boundary RecordWriter keeps when it stores a user id
 * without a foreign key (§5.2) — so the application resolves a person's groups
 * and their own grants and folds them in here, and the engine is handed the
 * answer rather than the question.
 *
 * **Nothing can deny.** Grants are additive, so folding them together is a
 * maximum (see PermissionScope::widest()) and never a precedence table. That is
 * what keeps "why can this person still see that" from becoming a question with a
 * complicated answer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PermissionSet
{
    /**
     * @param array<string, array<string, PermissionScope>> $scopes module key => action value => scope
     */
    private function __construct(
        private array $scopes,
        private bool $unrestricted,
    ) {
    }

    /** A person with no grants at all, which is what everybody starts as. */
    public static function nothing(): self
    {
        return new self([], false);
    }

    /**
     * May everything, everywhere, always — what ROLE_ADMIN resolves to.
     *
     * A bypass rather than a group somebody could be removed from. An
     * "Administrators" group that can be stripped reintroduces exactly the
     * lock-out §8.4.1 was built to refuse, and there is no support desk behind
     * this: getting back in would mean a console command against the customer's
     * database.
     */
    public static function unrestricted(): self
    {
        return new self([], true);
    }

    /**
     * This set plus one grant, merged at the wider of the two scopes.
     *
     * A grant naming an action that cannot be scoped is stored at All whatever it
     * says, because "add, but only the ones you own" describes nothing.
     */
    public function with(string $moduleKey, ModuleAction $action, PermissionScope $scope): self
    {
        if ($this->unrestricted) {
            return $this;
        }

        $effective = $action->isScopable() ? $scope : PermissionScope::All;
        $existing = $this->scopes[$moduleKey][$action->value] ?? null;

        $scopes = $this->scopes;
        $scopes[$moduleKey][$action->value] = $existing?->widest($effective) ?? $effective;

        return new self($scopes, false);
    }

    /**
     * How far this person's grant on that action reaches, or null if they have
     * none.
     *
     * Null and Own are different answers and callers must not conflate them: null
     * means the list is not theirs to see at all, Own means it is theirs to see
     * one row of.
     */
    public function scopeFor(string $moduleKey, ModuleAction $action): ?PermissionScope
    {
        if ($this->unrestricted) {
            return PermissionScope::All;
        }

        return $this->scopes[$moduleKey][$action->value] ?? null;
    }

    public function allows(string $moduleKey, ModuleAction $action): bool
    {
        return $this->scopeFor($moduleKey, $action) !== null;
    }

    /**
     * Whether this action is theirs only for records they own — the question the
     * query layer asks before deciding whether to add its predicate.
     */
    public function isLimitedToOwn(string $moduleKey, ModuleAction $action): bool
    {
        return $this->scopeFor($moduleKey, $action) === PermissionScope::Own;
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    /**
     * The modules this person may do anything at all in.
     *
     * Empty for an administrator, whose answer is "all of them" and who therefore
     * cannot be described by a list — callers wanting navigation have to check
     * isUnrestricted() first. Said here because a method returning [] for the one
     * person who may do everything is a trap otherwise.
     *
     * @return list<string>
     */
    public function moduleKeys(): array
    {
        return array_keys($this->scopes);
    }
}

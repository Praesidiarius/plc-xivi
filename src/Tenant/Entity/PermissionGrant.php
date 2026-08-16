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

namespace App\Tenant\Entity;

use App\Tenant\Repository\PermissionGrantRepository;
use App\Tenant\Security\PermissionVerbs;
use Doctrine\ORM\Mapping as ORM;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Permission\PermissionVerb;

/**
 * One statement of the form "this holder may do this to that, this far" (§7.5).
 *
 * The only thing the permission system stores. The *catalogue* of what could be
 * granted is worked out at runtime — the verbs crossed with the customer's
 * installed modules, plus the areas and the store — so there is nothing to seed
 * on install and nothing to migrate when a new action, a new area or a whole new
 * axis ships.
 *
 * **One table for both group grants and user grants**, not two. Resolving a
 * person's permissions is a union of the two, and two tables would mean writing
 * that union twice and having it disagree once. Exactly one holder is set, which
 * the database enforces with a check constraint rather than this class hoping.
 *
 * **`moduleKey` is a string, not a relation to the module definition.** A grant
 * for a module the customer later uninstalls goes inert rather than cascading
 * away, and reinstalling brings it back — the same reasoning as history's
 * denormalised user label (§5.2). A grant is a statement of intent, not a join.
 *
 * It also holds subjects the definitions were never going to have: `@profile` for
 * the instance's own settings (XIV-12) and `@store` for the store (XIV-6). The
 * name has stayed `moduleKey` rather than churning through every caller for a
 * rename that would improve nothing a docblock cannot.
 *
 * **`action` is stored as a plain string rather than with `enumType`**, and that
 * is the one departure this class makes from its neighbours. Since XIV-6 the
 * column holds a verb from either of two disjoint vocabularies — ModuleAction for
 * a module's records, StoreAction for the store — and `enumType:` names exactly
 * one class, so Doctrine would refuse to hydrate half the rows it wrote. The
 * typing has moved one layer out instead: the column is a string and
 * {@see getAction()} hands back a {@see PermissionVerb}, resolved through
 * {@see PermissionVerbs}, which is the only thing that knows there are two.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: PermissionGrantRepository::class)]
#[ORM\Table(name: 'permission_grant')]
class PermissionGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Named "holder" rather than "group"/"user" because exactly one of the two is
     * set and the pair is really one concept: whoever this grant is attached to.
     *
     * `holderGroup` also sidesteps a DQL hazard — `group` is a keyword there, and
     * a property called that is a query waiting to fail on a parser rather than
     * on a test.
     */
    #[ORM\ManyToOne(targetEntity: PermissionGroup::class, inversedBy: 'grants')]
    #[ORM\JoinColumn(name: 'group_id', nullable: true, onDelete: 'CASCADE')]
    private ?PermissionGroup $holderGroup = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'permissionGrants')]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $holderUser = null;

    private function __construct(
        #[ORM\Column(name: 'module_key', length: 63)]
        private string $moduleKey,
        /**
         * The verb's stored value; read it back through {@see getAction()}.
         *
         * Widened from 16 to 31 by XIV-80, whose `follow_up_complete` is eighteen
         * characters. The catalogue itself needs no migration — it is the enums
         * crossed with the customer's modules, worked out at runtime (§8.4) — but
         * the string still has to fit, and `send_email` had already been kept
         * short once to make it. 31 is the width `<module>_history.action` uses
         * for the same kind of word, so the next verb is somebody's naming
         * decision rather than a schema change.
         */
        #[ORM\Column(name: 'action', length: 31)]
        private string $actionValue,
        #[ORM\Column(length: 8, enumType: PermissionScope::class)]
        private PermissionScope $scope,
    ) {
    }

    /**
     * Both constructors go through here, so the one rule about scope is applied
     * once: an action that cannot be scoped is stored at All whatever the caller
     * asked for, because "add, but only the ones you own" describes nothing.
     * Correcting it rather than refusing keeps a UI bug from becoming a 500.
     */
    private static function create(string $moduleKey, PermissionVerb $action, PermissionScope $scope): self
    {
        return new self(
            $moduleKey,
            (string) $action->value,
            $action->isScopable() ? $scope : PermissionScope::All,
        );
    }

    public static function forGroup(
        PermissionGroup $group,
        string $moduleKey,
        PermissionVerb $action,
        PermissionScope $scope = PermissionScope::All,
    ): self {
        $grant = self::create($moduleKey, $action, $scope);
        $grant->holderGroup = $group;
        $group->addGrant($grant);

        return $grant;
    }

    public static function forUser(
        User $user,
        string $moduleKey,
        PermissionVerb $action,
        PermissionScope $scope = PermissionScope::All,
    ): self {
        $grant = self::create($moduleKey, $action, $scope);
        $grant->holderUser = $user;
        $user->addPermissionGrant($grant);

        return $grant;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHolderGroup(): ?PermissionGroup
    {
        return $this->holderGroup;
    }

    public function getHolderUser(): ?User
    {
        return $this->holderUser;
    }

    public function getModuleKey(): string
    {
        return $this->moduleKey;
    }

    /**
     * The verb, in whichever vocabulary it belongs to (§8.4.3).
     *
     * Throws on a value no vocabulary knows, which can only be a row this
     * application did not write. Failing loudly is right: the alternative is a
     * null that every caller has to think about, to describe a grant that cannot
     * exist.
     */
    public function getAction(): PermissionVerb
    {
        return PermissionVerbs::tryFrom($this->actionValue) ?? throw new \LogicException(sprintf(
            'Permission grant %s names action "%s", which is in no known vocabulary.',
            (string) $this->id,
            $this->actionValue,
        ));
    }

    public function getScope(): PermissionScope
    {
        return $this->scope;
    }

    public function setScope(PermissionScope $scope): void
    {
        $this->scope = $this->getAction()->isScopable() ? $scope : PermissionScope::All;
    }
}

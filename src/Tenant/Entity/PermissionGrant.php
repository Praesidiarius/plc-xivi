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
use Doctrine\ORM\Mapping as ORM;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * One statement of the form "this holder may do this to that module's records,
 * this far" (§7.5).
 *
 * The only thing the permission system stores. The *catalogue* of what could be
 * granted is ModuleAction crossed with the customer's installed modules, worked
 * out at runtime, so there is nothing to seed on install and nothing to migrate
 * when a new action ships.
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
        #[ORM\Column(length: 16, enumType: ModuleAction::class)]
        private ModuleAction $action,
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
    private static function create(string $moduleKey, ModuleAction $action, PermissionScope $scope): self
    {
        return new self($moduleKey, $action, $action->isScopable() ? $scope : PermissionScope::All);
    }

    public static function forGroup(
        PermissionGroup $group,
        string $moduleKey,
        ModuleAction $action,
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
        ModuleAction $action,
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

    public function getAction(): ModuleAction
    {
        return $this->action;
    }

    public function getScope(): PermissionScope
    {
        return $this->scope;
    }

    public function setScope(PermissionScope $scope): void
    {
        $this->scope = $this->action->isScopable() ? $scope : PermissionScope::All;
    }
}

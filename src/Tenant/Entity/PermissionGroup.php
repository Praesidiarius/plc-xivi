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

use App\Tenant\Repository\PermissionGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named set of grants, so permissions can be given to a job rather than to a
 * person (§7.5).
 *
 * Groups exist because the alternative — granting every action to every user
 * individually — is a screen nobody keeps correct after the third colleague. A
 * user in a group has everything that group holds, plus anything granted to them
 * directly; the two are added, never subtracted.
 *
 * In the tenant database, like users themselves (§8.1): a group is one customer's
 * idea of how their office is organised and means nothing at another.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: PermissionGroupRepository::class)]
#[ORM\Table(name: 'permission_group')]
#[ORM\UniqueConstraint(name: 'uniq_permission_group_key', columns: ['group_key'])]
#[ORM\HasLifecycleCallbacks]
class PermissionGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The grants this group holds.
     *
     * Removing a group takes its grants with it — they describe the group and
     * have no meaning without it, unlike a user's own grants which survive
     * everything except the user.
     *
     * @var Collection<int, PermissionGrant>
     */
    #[ORM\OneToMany(targetEntity: PermissionGrant::class, mappedBy: 'holderGroup', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $grants;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'permissionGroups')]
    private Collection $members;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        /**
         * A stable handle, so something in code can name a group without
         * depending on what the customer calls it today. The label is the part
         * people read and rename freely — the same split §5.4 makes between a
         * field's key and its label, for the same reason.
         */
        #[ORM\Column(name: 'group_key', length: 63)]
        private string $key,
        #[ORM\Column(length: 255)]
        private string $label,
    ) {
        $this->grants = new ArrayCollection();
        $this->members = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /** @return Collection<int, PermissionGrant> */
    public function getGrants(): Collection
    {
        return $this->grants;
    }

    public function addGrant(PermissionGrant $grant): void
    {
        if (!$this->grants->contains($grant)) {
            $this->grants->add($grant);
        }
    }

    public function removeGrant(PermissionGrant $grant): void
    {
        $this->grants->removeElement($grant);
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

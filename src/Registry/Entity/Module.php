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

namespace App\Registry\Entity;

use App\Registry\Repository\ModuleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the platform has decided about one module — today, only its state (XIV-7).
 *
 * The row is *not* the module. What modules exist is a property of the build, and
 * `Xivi\Core\Module\ModuleRegistry` answers it; this table only carries the part
 * of the answer code cannot hold, because publishing is an operational decision
 * rather than a change to the module — the same reason a tenant's plan and status
 * live out here (§4) while its shape lives in code (§6.1).
 *
 * Which is why **a module with no row is in development**: that is the default a
 * new module gets for free, without a sync step whose only job would be to write
 * the answer down. A row appears the first time somebody decides otherwise, and
 * `App\Registry\Catalog\ModuleCatalog` is what joins the two halves.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: ModuleRepository::class)]
#[ORM\Table(name: 'module')]
#[ORM\UniqueConstraint(name: 'uniq_module_key', columns: ['module_key'])]
#[ORM\HasLifecycleCallbacks]
class Module
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        /**
         * The blueprint key, e.g. `contact`. No foreign key exists or could: the
         * thing it names lives in the build, not in a table.
         */
        #[ORM\Column(name: 'module_key', length: 64)]
        private string $key,
        #[ORM\Column(enumType: ModuleState::class, length: 32)]
        private ModuleState $state = ModuleState::Development,
    ) {
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

    public function getState(): ModuleState
    {
        return $this->state;
    }

    /**
     * Kept even when it goes back to development, rather than deleting the row:
     * the two are the same to every reader, and the timestamp of a module being
     * pulled out of the store is worth more than a tidy table.
     */
    public function setState(ModuleState $state): void
    {
        $this->state = $state;
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

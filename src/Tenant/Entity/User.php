<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use App\Tenant\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A person who can sign in to one customer's installation.
 *
 * Users live in the tenant database, not the control plane. Pooling them
 * centrally would put customer names, emails and password hashes into one shared
 * database while claiming physical isolation for everything else
 * (docs/architecture.md §4) — and it would make export-on-churn stop being a
 * single pg_dump. The cost is that the same person at two customers is two rows,
 * which for a B2B CRM is the honest representation anyway.
 *
 * Consequence worth remembering: user identifiers are only unique *within* a
 * tenant. Anything that trusts an identifier across a request boundary — the
 * session above all — has to carry the tenant with it.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
// "user" is reserved in PostgreSQL; naming the table explicitly avoids quoting
// every reference to it forever.
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $password = '';

    /** @var list<string> roles beyond the implicit ROLE_USER */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct(
        /** The login, and therefore also the security identifier. */
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 255)]
        private string $name,
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The security layer requires a non-empty identifier, and the column type
     * cannot express that — so it is checked here rather than assumed. A row with
     * an empty email could never authenticate anyway.
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->email ?: throw new \LogicException(sprintf('User %s has no email.', $this->id ?? 0));
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = array_values(array_filter($roles, static fn (string $role): bool => $role !== 'ROLE_USER'));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** @param string $hashedPassword never a plaintext password */
    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function eraseCredentials(): void
    {
        // No plaintext is ever held on this object.
    }
}

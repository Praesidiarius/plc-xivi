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

namespace App\ControlPlane\Entity;

use App\ControlPlane\Repository\OperatorRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Somebody who runs this installation, as opposed to somebody who uses one
 * (XIV-57).
 *
 * **This is the first identity in the system that is not a tenant user**, and it
 * is the whole point of the ticket. Every other person who can sign in is a row
 * in exactly one customer's database (§8.1), which is what makes cross-tenant
 * leakage structurally impossible rather than carefully avoided: a request
 * resolves one tenant and the security provider can only ever ask that one
 * database who somebody is. An operator does not fit that shape, because an
 * operator's subject matter is *the set of tenants* — and no customer's database
 * is the right place to keep the key to every other customer's.
 *
 * So this row lives in the control plane, and the argument for why it is not a
 * promoted user of some designated tenant is in §8.9. The short version: that
 * design makes the smallest customer's compromised administrator into a
 * compromised operator.
 *
 * **Deliberately smaller than `App\Tenant\Entity\User`, and it should stay that
 * way.** No permission grants, no groups, no locale, no invitation seed, no
 * avatar. Every one of those exists because a tenant user is one of many people
 * in a company with different jobs; an operator is one of the two or three
 * people who run the platform, and inventing a permission model for them before
 * there is a second kind of operator would be modelling a guess. When there
 * really is a distinction to draw — a read-only operator, say — that is the
 * moment to add it, with the case in hand.
 *
 * **No `active` flag, unlike the tenant user.** A deactivated tenant user is a
 * colleague who left a company that keeps their records; there is no
 * corresponding state here, because nothing in the control plane belongs to an
 * operator personally and revoking one is therefore deleting the row. A boolean
 * with no screen to set it from and no command to write it would be a promise
 * nothing keeps.
 *
 * **No `__serialize()` here, unlike the tenant user, and that is a real
 * difference rather than an omission.** That class lists its properties by hand
 * because it holds two Doctrine collections, which come back from the session
 * detached and would be written into the session store on every request. This
 * object is scalars only, so PHP's default serialization is both correct and
 * complete, and a hand-written list would be one more place to forget a column.
 * If a collection is ever added here, that stops being true — which is why it is
 * written down rather than left to be noticed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: OperatorRepository::class)]
// "operator" is not reserved in PostgreSQL, but it is a keyword in enough
// dialects that spelling the table name out costs nothing and settles it.
#[ORM\Table(name: 'operator')]
#[ORM\UniqueConstraint(name: 'uniq_operator_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class Operator implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * The one role an operator has, and the only role that reaches a
     * control-plane route.
     *
     * **It is deliberately not `ROLE_USER`**, which is the load-bearing half of
     * this constant. `access_control` is global rather than per firewall, so the
     * `^/` rule that guards the whole tenant application applies to a request on
     * the control-plane host too — and an operator holding no `ROLE_USER` is
     * refused by it. That is what stops an operator wandering into a tenant
     * screen with no tenant resolved and collecting a 500 from a connection that
     * is deliberately unusable; instead they are told no, which is both the
     * truthful answer and the safe one.
     *
     * The reverse direction is guarded by the firewall and by
     * {@see \App\ControlPlane\Security\ControlPlaneHost}, not by this string: a
     * tenant user who somehow acquired `ROLE_OPERATOR` in their own database
     * still cannot reach a control-plane route, because the route does not exist
     * on their hostname and the firewall that serves it authenticates against
     * the control plane. A role is not a boundary here; the host is.
     */
    public const string ROLE = 'ROLE_OPERATOR';

    /**
     * The floor for an operator's password.
     *
     * The same twelve characters a tenant user's own password has to reach
     * ({@see \App\Tenant\Entity\User::MINIMUM_PASSWORD_LENGTH}), and for a
     * stronger reason: this account is not scoped to one customer.
     */
    public const int MINIMUM_PASSWORD_LENGTH = 12;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

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
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->email ?: throw new \LogicException(sprintf('Operator %s has no email.', $this->id ?? 0));
    }

    /**
     * @return list<string>
     *
     * @see ROLE for why `ROLE_USER` is not in here
     */
    public function getRoles(): array
    {
        return [self::ROLE];
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

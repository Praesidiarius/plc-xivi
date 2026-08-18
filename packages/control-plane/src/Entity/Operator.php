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

namespace Xivi\ControlPlane\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Xivi\ControlPlane\Repository\OperatorRepository;

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
 * **There is an `active` flag after all, and XIV-57 was right to refuse it at the
 * time** (XIV-92, §8.9). The refusal rested on one sentence — a boolean with no
 * screen to set it from and no command to write it is a promise nothing keeps —
 * and that sentence was true of a ticket whose only lifecycle verb was *create*.
 * It stopped being true the moment revocation was asked for at all, because the
 * question then is not *should this column exist* but *what does revoking do*,
 * and the two available answers are this column and `DELETE`. §8.9 argues the
 * choice out; the short version is that deletion is the one lifecycle step that
 * cannot be undone by the person who has just realised they typed the wrong
 * address, and that revoking an operator is done in a hurry by construction —
 * somebody has left, or a credential has leaked.
 *
 * The flag is deliberately written **before** anything attributes anything to an
 * operator rather than after. Once a `signup_request` or a provisioning record
 * carries "which operator did this", a deletable operator forces a choice
 * between an audit trail that erases itself exactly when somebody is revoked and
 * a foreign key that refuses the revocation — and both of those are discovered
 * with the migration already in production.
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
     * {@see \Xivi\ControlPlane\Security\ControlPlaneHost}, not by this string: a
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

    /**
     * Whether this operator may still sign in (XIV-92).
     *
     * **Two mechanisms read it, and neither covers the other's case** — the same
     * pair `User::active` needed on the tenant side, for the same framework
     * reason. {@see \Xivi\ControlPlane\Security\ActiveOperatorChecker} refuses
     * the sign-in, and {@see \Xivi\ControlPlane\EventListener\RevokedOperatorListener}
     * ends a session that already exists, because Symfony's `ContextListener`
     * compares identifier, password and roles when it restores a session and
     * never consults a user checker. Without the listener, revoking somebody
     * would take effect whenever their session happened to expire, which for the
     * one case this exists for — a person who has just left — is the wrong answer
     * for as long as that lasts.
     *
     * Not part of `getRoles()`, deliberately, even though returning an empty
     * array for a revoked operator would make `ContextListener` discard the token
     * on its own and save a class. It would do it by making a *role* mean
     * "revoked", which is a second meaning for a string that `access_control`
     * already reads, and it would break the sign-in refusal's message — Symfony
     * would report a plain access denial where the honest answer is that the
     * account was withdrawn.
     */
    #[ORM\Column]
    private bool $active = true;

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

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Set through {@see \Xivi\ControlPlane\Security\OperatorManager} and nowhere
     * else, which is where the last-active-operator refusal lives. A setter that
     * anything may call is one that will eventually be called by something that
     * has not made that check.
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
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

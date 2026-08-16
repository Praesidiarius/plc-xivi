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

use App\Tenant\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
// "user" is reserved in PostgreSQL; naming the table explicitly avoids quoting
// every reference to it forever.
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * The floor for a password somebody chooses for themselves.
     *
     * Generated ones are 96 bits and nowhere near this limit; this exists for the
     * box on the account page, where the alternative is a four-character password
     * on an account that can read every customer record.
     */
    public const int MINIMUM_PASSWORD_LENGTH = 12;

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

    /**
     * Set whenever the system generates a password, cleared when the owner picks
     * their own.
     *
     * A generated password is one at least two people know: the administrator
     * read it off a screen and passed it on somehow (§8.5). It is a way in, not
     * yet a credential, and the account is held at the password page until the
     * owner has replaced it.
     */
    #[ORM\Column]
    private bool $mustChangePassword = false;

    /**
     * What makes an invitation link this person's, and makes it stop being one
     * (XIV-1).
     *
     * **It is not the token.** The link an invited colleague receives is signed
     * by `SignatureHasher` with `kernel.secret`, over this value together with
     * their id, their password hash and the moment the link expires. So a copy of
     * this database is not enough to mint a link, and this column is not a
     * credential sitting in a row waiting to be read — which is the property §8.8
     * needed and the reason there is no invitation table storing a hashed token
     * beside it.
     *
     * **Rotating it is how a link dies.** Symfony's login links are stateless by
     * design: nothing is written down, so nothing can be revoked, and the two
     * things an invitation has to be able to do — stop working once it has been
     * used, and be superseded when an administrator sends a second one — are both
     * revocations. Putting one rotating value into the signature buys both, and
     * costs a column instead of a table. Every write of this field invalidates
     * every link already in somebody's mailbox.
     *
     * Null for everybody who has never been invited, which is most people: the
     * signature hasher reads a null property as an empty string, so it needs no
     * default and no backfill.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $invitationSeed = null;

    /**
     * The groups this person belongs to, and therefore everything those groups
     * are granted (§7.5).
     *
     * The owning side, because membership is edited from both the user's page and
     * the group's, and one of them has to be the side Doctrine writes.
     *
     * @var Collection<int, PermissionGroup>
     */
    #[ORM\ManyToMany(targetEntity: PermissionGroup::class, inversedBy: 'members')]
    #[ORM\JoinTable(name: 'user_group')]
    #[ORM\JoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'group_id', onDelete: 'CASCADE')]
    private Collection $permissionGroups;

    /**
     * Grants made to this person specifically, on top of whatever their groups
     * give them.
     *
     * Additive only — there is no such thing as a grant that takes something
     * away, which is what keeps resolution a maximum rather than a precedence
     * table nobody can hold in their head.
     *
     * @var Collection<int, PermissionGrant>
     */
    #[ORM\OneToMany(targetEntity: PermissionGrant::class, mappedBy: 'holderUser', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $permissionGrants;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * Which language this person reads the application in (XIV-8).
     *
     * Null means "whatever the application defaults to", which is not the same
     * as storing 'en': somebody who never chose keeps following the default if
     * it ever moves, and somebody who chose English keeps English. Two facts,
     * two values.
     *
     * Per person rather than per customer, because one office is not one
     * language — a Swiss company has German and French speakers in it.
     */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    /**
     * Which country's conventions this person reads in (XIV-50), as an ISO
     * 3166-1 alpha-2 code.
     *
     * Separate from the language, because they are separate questions: an
     * English-speaking colleague at a Swiss company wants English words and
     * Swiss figures, which is an ordinary hire rather than an exotic case.
     *
     * Null follows the installation's own (§8.6) — a different promise from
     * naming that same country, which stops following it if the company moves.
     */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $region = null;

    /**
     * Which zone this person reads a moment in (XIV-83), as an IANA identifier —
     * `Europe/Zurich`, never an offset and never an abbreviation.
     *
     * **The third setting of the shape the two above have**, and the one with a
     * step they do not: null falls through to the installation's, and from there
     * to whatever the effective *region* implies where that country has exactly
     * one zone. Which is why most people and most customers will never touch
     * this — a Swiss company has already said Switzerland, and Switzerland is
     * `Europe/Zurich` with nothing left to ask.
     *
     * Null is therefore the ordinary state rather than a gap, and it is a
     * different fact from naming the same zone the company named: somebody who
     * left this empty follows the company if it moves, and somebody who typed
     * `Europe/Zurich` keeps Zurich. The same two-facts-two-values argument the
     * language above is stored under.
     *
     * An identifier and never an offset, because an offset is a fact about one
     * moment rather than about a place: `+01:00` is Zurich in January and wrong
     * in July, and a stored offset would be a clock that is right twice a year.
     * The zone database knows when the transitions are; a column cannot.
     *
     * Storage is unaffected by any of this — moments are absolute UTC in
     * `timestamptz` columns and stay that way (§8.4.4). This is a reading
     * preference, and nothing but the display layer ever asks for it.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timezone = null;

    public function __construct(
        /** The login, and therefore also the security identifier. */
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 255)]
        private string $name,
    ) {
        $this->permissionGroups = new ArrayCollection();
        $this->permissionGrants = new ArrayCollection();
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

    /**
     * Changing this changes the login, and therefore the security identifier the
     * session holds. Symfony compares the two when it refreshes a user, so a
     * person who renames their own account is signed out and signs in again as
     * the new address — which is the correct outcome, not a bug to work around.
     */
    public function setEmail(string $email): void
    {
        $this->email = mb_strtolower(trim($email));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
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

    /**
     * Whether this account has a password at all.
     *
     * Empty is a real state rather than a broken row: somebody invited by email
     * has never been given one, and per XIV-1 none was generated for them — an
     * unused generated password is a credential nobody rotates. Nothing can
     * authenticate against it, from either direction: `CheckCredentialsListener`
     * refuses an empty presented password before the hasher is reached, and
     * `password_verify()` against an empty hash is false whatever is presented.
     *
     * Which is also what makes this the honest name for "awaiting an invitation":
     * the state is the absence of the credential, not a flag beside it that
     * something could forget to clear.
     */
    public function hasPassword(): bool
    {
        return $this->password !== '';
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function setMustChangePassword(bool $must): void
    {
        $this->mustChangePassword = $must;
    }

    /** Read by `SignatureHasher` through the property accessor, which is why it is public. */
    public function getInvitationSeed(): ?string
    {
        return $this->invitationSeed;
    }

    /**
     * Every call to this kills every invitation link already sent to this person.
     *
     * Callers go through `UserManager::rotateInvitationSeed()` rather than here,
     * so the flush that makes the revocation real cannot be forgotten.
     */
    public function setInvitationSeed(?string $seed): void
    {
        $this->invitationSeed = $seed;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /** @return Collection<int, PermissionGroup> */
    public function getPermissionGroups(): Collection
    {
        return $this->permissionGroups;
    }

    public function addPermissionGroup(PermissionGroup $group): void
    {
        if (!$this->permissionGroups->contains($group)) {
            $this->permissionGroups->add($group);
        }
    }

    public function removePermissionGroup(PermissionGroup $group): void
    {
        $this->permissionGroups->removeElement($group);
    }

    /** @return Collection<int, PermissionGrant> */
    public function getPermissionGrants(): Collection
    {
        return $this->permissionGrants;
    }

    public function addPermissionGrant(PermissionGrant $grant): void
    {
        if (!$this->permissionGrants->contains($grant)) {
            $this->permissionGrants->add($grant);
        }
    }

    public function removePermissionGrant(PermissionGrant $grant): void
    {
        $this->permissionGrants->removeElement($grant);
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /** @param string|null $locale null to follow the application default */
    public function setLocale(?string $locale): void
    {
        $this->locale = $locale === null || trim($locale) === '' ? null : $locale;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    /** @param string|null $region an ISO 3166-1 alpha-2 code, or null to follow the installation's */
    public function setRegion(?string $region): void
    {
        $region = strtoupper(trim((string) $region));

        $this->region = $region === '' ? null : $region;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    /**
     * @param string|null $timezone an IANA identifier, or null to follow the
     *                              installation's — see the property. Case is
     *                              preserved rather than folded: `Europe/Zurich`
     *                              is the identifier and `europe/zurich` is not
     *                              one, unlike a country code where upper case is
     *                              the only spelling there is. Whether it names a
     *                              real zone is the caller's question, the same
     *                              call `setRegion()` above makes about countries.
     */
    public function setTimezone(?string $timezone): void
    {
        $timezone = trim((string) $timezone);

        $this->timezone = $timezone === '' ? null : $timezone;
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

    /**
     * What of this user the session carries — everything except the permission
     * collections.
     *
     * This object is serialized into the session by the security token, and a
     * Doctrine collection in there is two problems. It arrives back detached, so
     * touching it throws rather than lazily loading; and a person in several
     * groups would have their whole permission model written into a cookie store
     * on every request, to be thrown away and refreshed immediately.
     *
     * Refreshed is the operative word: ContextListener reloads the user from the
     * provider on each request and compares identifier, password and roles, so
     * nothing here is trusted for longer than it takes to do that (§8.2). The
     * collections come back from the database with it.
     *
     * Listed property by property on purpose. `get_object_vars()` minus the two
     * would keep itself up to date, but it also cannot be checked — and a new
     * column silently missing from the session is a bug that only shows up as a
     * user who is subtly not themselves. **A column added above belongs here
     * too.**
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'password' => $this->password,
            'roles' => $this->roles,
            'active' => $this->active,
            'mustChangePassword' => $this->mustChangePassword,
            'invitationSeed' => $this->invitationSeed,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'lastLoginAt' => $this->lastLoginAt,
            'locale' => $this->locale,
            'region' => $this->region,
            'timezone' => $this->timezone,
        ];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        \assert(\is_array($data['roles']));

        $this->id = $data['id'] === null ? null : (int) $data['id'];
        $this->email = (string) $data['email'];
        $this->name = (string) $data['name'];
        $this->password = (string) $data['password'];
        /** @var list<string> $roles */
        $roles = array_values($data['roles']);
        $this->roles = $roles;
        $this->active = (bool) $data['active'];
        $this->mustChangePassword = (bool) $data['mustChangePassword'];
        // Absent rather than null for a token minted before this column existed,
        // for the same reason `region` below is written this way.
        $this->invitationSeed = ($data['invitationSeed'] ?? null) === null ? null : (string) $data['invitationSeed'];

        \assert($data['createdAt'] instanceof \DateTimeImmutable);
        \assert($data['updatedAt'] instanceof \DateTimeImmutable);
        \assert($data['lastLoginAt'] === null || $data['lastLoginAt'] instanceof \DateTimeImmutable);

        $this->createdAt = $data['createdAt'];
        $this->updatedAt = $data['updatedAt'];
        $this->lastLoginAt = $data['lastLoginAt'];
        $this->locale = $data['locale'] === null ? null : (string) $data['locale'];
        // Absent rather than null for a token minted before this column existed:
        // somebody signed in across the deploy keeps their session instead of
        // being logged out by a formatting preference.
        $this->region = ($data['region'] ?? null) === null ? null : (string) $data['region'];
        // Absent rather than null for the same reason, one deploy later
        // (XIV-83): being signed out is a strange price for a column about
        // reading clocks.
        $this->timezone = ($data['timezone'] ?? null) === null ? null : (string) $data['timezone'];

        // Empty rather than absent: a typed property left uninitialised throws on
        // read, and this object is live until the provider replaces it.
        $this->permissionGroups = new ArrayCollection();
        $this->permissionGrants = new ArrayCollection();
    }
}

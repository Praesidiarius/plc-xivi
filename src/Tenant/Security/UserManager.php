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

namespace App\Tenant\Security;

use App\Tenancy\Security\PasswordGenerator;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The people who can sign in to the customer being served.
 *
 * Operates on the tenant the request resolved to, and never takes one as an
 * argument — that is `UserCreator`'s job, for provisioning and console commands
 * where there is no request to have resolved anything (§8.1). This one exists
 * because there is now a screen for it, and because everything it refuses is a
 * way of locking somebody out of a system with no support desk behind it.
 *
 * **Passwords are generated, never chosen by an administrator** (§8.5). One
 * mechanism for the first admin, for `tenant:user:create` and for this: 96 bits
 * from `random_bytes`, shown once and hashed immediately. An administrator who
 * picks a colleague's password knows their password, which is a different system
 * from the one this is trying to be. Changing it afterwards is the account
 * owner's to do, and needs the current one.
 *
 * **Or no password at all** (XIV-1). An invited colleague is created by
 * `createWithoutPassword()` and given nothing to sign in with; the link in their
 * mail is what gets them through the door, and `setInitialPassword()` is where
 * they end up. That is a third way an account can begin and it deliberately does
 * not generate one to throw away — a password nobody was ever told is still a
 * password nobody ever rotates. §8.8 is the long version.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class UserManager
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    public function __construct(
        private UserRepository $users,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @param list<string> $roles
     * @param string|null  $password only ever passed by provisioning and the console,
     *                               which have to be able to seed a known one; the
     *                               screens never do, and generate instead
     *
     * @return array{User, string} the user, and the one moment their password exists in the clear
     *
     * @throws UserChangeRefused
     */
    public function create(string $email, string $name, array $roles = [], #[\SensitiveParameter] ?string $password = null): array
    {
        $user = $this->add($email, $name, $roles);
        $password = $this->assignPassword($user, $password);

        $this->entityManager->flush();

        return [$user, $password];
    }

    /**
     * The other way a colleague arrives: invited, with no credential at all
     * (XIV-1).
     *
     * **Nothing is generated here, and that is the whole point.** A password
     * created for somebody who is about to choose their own is a credential
     * nobody rotates: it sits in the row, valid, for as long as the account does,
     * and it was never needed because the invitation link is what lets them in.
     * So the hash stays empty — a state nothing can authenticate against from
     * either direction (see `User::hasPassword()`) — and the hold is set for the
     * same reason it is set for a generated one: whatever got them through the
     * door is not yet a credential they own.
     *
     * The invitation itself is `UserInvitations`' to send. This only makes the
     * account it will be sent to, because "create the user" and "put a link in
     * the post" fail in different ways and the first must survive the second.
     *
     * @param list<string> $roles
     *
     * @throws UserChangeRefused
     */
    public function createWithoutPassword(string $email, string $name, array $roles = []): User
    {
        $user = $this->add($email, $name, $roles);
        $user->setMustChangePassword(true);

        $this->entityManager->flush();

        return $user;
    }

    /** @throws UserChangeRefused */
    public function updateProfile(User $user, string $email, string $name): void
    {
        $email = self::normalise($email);
        $existing = $this->users->findOneByEmail($email);

        if ($existing !== null && $existing->getId() !== $user->getId()) {
            throw UserChangeRefused::emailTaken($email);
        }

        $user->setEmail($email);
        $user->setName($name === '' ? $email : $name);

        $this->entityManager->flush();
    }

    /**
     * The language this person reads the application in (XIV-8).
     *
     * Null follows the application default rather than pinning English, which
     * are different promises: one keeps following the default if it moves.
     */
    public function setLocale(User $user, ?string $locale): void
    {
        $user->setLocale($locale);
        $this->entityManager->flush();
    }

    /** Which country's conventions this person reads in (XIV-50); null follows the installation's. */
    public function setRegion(User $user, ?string $region): void
    {
        $user->setRegion($region);
        $this->entityManager->flush();
    }

    /**
     * Which zone this person reads a moment in (XIV-83).
     *
     * Null follows the installation's, and from there whatever the region
     * implies — the extra step this chain has and the other two do not, which is
     * why leaving it empty is the answer nearly everybody keeps.
     */
    public function setTimezone(User $user, ?string $timezone): void
    {
        $user->setTimezone($timezone);
        $this->entityManager->flush();
    }

    /**
     * Which widgets this person keeps on their dashboard, and in what order
     * (XIV-66).
     *
     * The fourth of these and the only one whose null and whose empty answer are
     * different things: null follows the installation's layout, and an empty list
     * is a dashboard somebody deliberately cleared. Both arrive here as they were
     * decided, and nothing on this path helpfully turns one into the other.
     *
     * @param list<string>|null $layout widget keys in the order they should be
     *                                  drawn, or null to follow the installation's
     */
    public function setDashboardLayout(User $user, ?array $layout): void
    {
        $user->setDashboardLayout($layout);
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $roles
     *
     * @throws UserChangeRefused
     */
    public function setRoles(User $user, array $roles, ?User $actor): void
    {
        $wasAdmin = self::isAdmin($user);
        $willBeAdmin = \in_array(self::ROLE_ADMIN, $roles, true);

        if ($wasAdmin && !$willBeAdmin) {
            // Two ways to end up with nobody able to administer this
            // installation, and they need saying differently: one is a mistake
            // about yourself, the other about everyone.
            if ($actor !== null && $actor->getId() === $user->getId()) {
                throw UserChangeRefused::ownAdminRole();
            }

            $this->refuseIfLastAdmin($user);
        }

        $user->setRoles($roles);
        $this->entityManager->flush();
    }

    /** @throws UserChangeRefused */
    public function setActive(User $user, bool $active, ?User $actor): void
    {
        if (!$active) {
            if ($actor !== null && $actor->getId() === $user->getId()) {
                throw UserChangeRefused::ownAccount();
            }

            $this->refuseIfLastAdmin($user);
        }

        $user->setActive($active);
        $this->entityManager->flush();
    }

    /**
     * A new generated password, replacing whatever was there.
     *
     * For the person who has forgotten theirs. It does not need the old one,
     * which is exactly why it is administrator-only.
     */
    public function resetPassword(User $user): string
    {
        $password = $this->assignPassword($user);
        $this->entityManager->flush();

        return $password;
    }

    /**
     * The first password an invited account ever has, chosen by its owner
     * (XIV-1).
     *
     * The sibling of `changeOwnPassword()`, and the difference is the one thing
     * it cannot ask for: there is no current password, because per XIV-1 none was
     * ever generated. What replaces that proof is how the person got here — they
     * arrived on a signed link sent to the address the account is named after,
     * and the firewall authenticated them on it before this screen was reachable
     * at all.
     *
     * **It refuses an account that already has one**, which is what keeps it from
     * being a way around `changeOwnPassword()`'s question. The controller decides
     * which of the two to call from the same fact, so the two checks agree; this
     * one is here because the fact is about the account rather than about the
     * form, and the caller that eventually forgets is the one this exists for.
     *
     * The seed is rotated on the way out even though accepting the link already
     * rotated it. Belt and braces on purpose: the moment an invitation has
     * produced a password is the moment it is unambiguously spent, and it is the
     * one place that is true no matter what path was taken to get here.
     *
     * @throws UserChangeRefused
     */
    public function setInitialPassword(User $user, #[\SensitiveParameter] string $new): void
    {
        if ($user->hasPassword()) {
            throw UserChangeRefused::passwordAlreadySet();
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $new));
        $user->setMustChangePassword(false);
        $user->setInvitationSeed(PasswordGenerator::machine());

        $this->entityManager->flush();
    }

    /**
     * A new seed, and therefore no invitation link that still works (XIV-1).
     *
     * Called twice in an invitation's life, for the two revocations a stateless
     * login link cannot do for itself: on the way out, so a second invitation
     * supersedes the first rather than leaving two live links; and on the way in,
     * so accepting one uses it up.
     *
     * `PasswordGenerator::machine()` because that is exactly what this is — 32
     * bytes from the CSPRNG that no human ever reads or types. Reused rather than
     * reinvented; a second random-value generator is a second one to get wrong.
     */
    public function rotateInvitationSeed(User $user): void
    {
        $user->setInvitationSeed(PasswordGenerator::machine());

        $this->entityManager->flush();
    }

    /**
     * The account owner changing their own, which needs the current one.
     *
     * Requiring it is not about the password being secret from its owner — it is
     * about an unattended session not being enough to take an account over.
     *
     * @throws UserChangeRefused
     */
    public function changeOwnPassword(User $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $current)) {
            throw UserChangeRefused::wrongPassword();
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $new));

        // Now only its owner knows it, which is the whole point of the hold.
        $user->setMustChangePassword(false);

        $this->entityManager->flush();
    }

    /**
     * Everyone who can sign in here, newest last.
     *
     * @return list<User>
     */
    public function all(): array
    {
        return $this->users->findBy([], ['name' => 'ASC']);
    }

    public static function isAdmin(User $user): bool
    {
        return \in_array(self::ROLE_ADMIN, $user->getRoles(), true);
    }

    /**
     * Counted in PHP rather than in SQL because roles are a JSON column and the
     * number of people who work at one customer is not a number worth writing a
     * JSONB predicate for.
     */
    private function refuseIfLastAdmin(User $user): void
    {
        if (!self::isAdmin($user) || !$user->isActive()) {
            return;
        }

        foreach ($this->users->findBy(['active' => true]) as $other) {
            if ($other->getId() !== $user->getId() && self::isAdmin($other)) {
                return;
            }
        }

        throw UserChangeRefused::lastAdmin();
    }

    /**
     * A persisted user with a name, an address nobody else here has, and no
     * credential yet — the part both ways of adding somebody share.
     *
     * @param list<string> $roles
     *
     * @throws UserChangeRefused
     */
    private function add(string $email, string $name, array $roles): User
    {
        $email = self::normalise($email);

        if ($this->users->emailIsTaken($email)) {
            throw UserChangeRefused::emailTaken($email);
        }

        $user = new User($email, $name === '' ? $email : $name);
        $user->setRoles($roles);

        $this->entityManager->persist($user);

        return $user;
    }

    /** @return string the plaintext, which exists only until this returns */
    private function assignPassword(User $user, #[\SensitiveParameter] ?string $password = null): string
    {
        // Only a password *this* generated has to be replaced. One handed in by
        // provisioning or a console command was chosen by whoever ran it, and
        // demanding they change it immediately would be telling them their own
        // decision was wrong.
        $user->setMustChangePassword($password === null);

        $password ??= PasswordGenerator::human();
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        return $password;
    }

    /** @throws UserChangeRefused */
    private static function normalise(string $email): string
    {
        $email = mb_strtolower(trim($email));

        return $email === '' ? throw UserChangeRefused::noEmail() : $email;
    }
}

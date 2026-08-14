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
        $email = self::normalise($email);

        if ($this->users->emailIsTaken($email)) {
            throw UserChangeRefused::emailTaken($email);
        }

        $user = new User($email, $name === '' ? $email : $name);
        $user->setRoles($roles);
        $password = $this->assignPassword($user, $password);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return [$user, $password];
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

    /** @return string the plaintext, which exists only until this returns */
    private function assignPassword(User $user, #[\SensitiveParameter] ?string $password = null): string
    {
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

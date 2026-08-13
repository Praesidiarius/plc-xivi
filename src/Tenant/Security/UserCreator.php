<?php

declare(strict_types=1);

namespace App\Tenant\Security;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\Security\PasswordGenerator;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a user inside one tenant's database.
 *
 * Takes the tenant explicitly and switches to it, rather than assuming the
 * ambient context: the callers are console commands and provisioning, where
 * there is no request to have resolved one.
 */
final readonly class UserCreator
{
    public function __construct(
        private TenantSwitcher $switcher,
        private UserRepository $users,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @param list<string> $roles
     *
     * @return string the plaintext password — generated when none is given, and
     *                the only moment it exists outside the caller's hands
     *
     * @throws UserAlreadyExists
     */
    public function create(
        Tenant $tenant,
        string $email,
        string $name,
        #[\SensitiveParameter] ?string $password = null,
        array $roles = [],
    ): string {
        $email = mb_strtolower(trim($email));
        $password ??= PasswordGenerator::human();

        if ($email === '') {
            throw new \InvalidArgumentException('A user needs an email address: it is the login.');
        }

        $this->switcher->runFor($tenant, function () use ($email, $name, $password, $roles, $tenant): void {
            if ($this->users->findOneByEmail($email) !== null) {
                throw UserAlreadyExists::in($tenant, $email);
            }

            $user = new User($email, $name);
            $user->setRoles($roles);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $this->entityManager->persist($user);
            $this->entityManager->flush();
        });

        return $password;
    }
}

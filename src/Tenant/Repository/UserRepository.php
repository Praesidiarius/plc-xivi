<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Tenant\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 *
 * Resolves through the `tenant` manager, so every query here reaches whichever
 * customer's database the current request resolved to — and throws rather than
 * guessing when there is no tenant.
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function emailIsTaken(string $email): bool
    {
        return $this->findOneByEmail($email) !== null;
    }
}

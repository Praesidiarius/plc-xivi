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

namespace App\ControlPlane\Repository;

use App\ControlPlane\Entity\Module;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Module>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class ModuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Module::class);
    }

    public function findOneByKey(string $key): ?Module
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Every module anybody has decided anything about, keyed by module key.
     *
     * One query rather than one per module: the catalogue's callers ask about the
     * whole build at once, and there are as many rows here as the platform has
     * ever published.
     *
     * @return array<string, Module>
     */
    public function allByKey(): array
    {
        $byKey = [];

        foreach ($this->findAll() as $module) {
            $byKey[$module->getKey()] = $module;
        }

        return $byKey;
    }
}

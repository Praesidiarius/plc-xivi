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

use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\PermissionGroup;
use App\Tenant\Repository\PermissionGroupRepository;
use App\Tenant\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * Creating groups and deciding what they hold (§7.5).
 *
 * The write side of the permission model, kept out of the controller for the
 * same reason UserManager is: the rules about what a change may do belong next
 * to the change, not next to the HTTP.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PermissionGroupManager
{
    public function __construct(
        private PermissionGroupRepository $groups,
        private UserRepository $users,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<PermissionGroup> */
    public function all(): array
    {
        return $this->groups->all();
    }

    public function find(int $id): ?PermissionGroup
    {
        return $this->groups->find($id);
    }

    public function create(string $label): PermissionGroup
    {
        $label = trim($label);
        $this->refuseBadName($label, null);

        $group = new PermissionGroup($this->freeKey($label), $label);
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }

    public function rename(PermissionGroup $group, string $label): void
    {
        $label = trim($label);
        $this->refuseBadName($label, $group);

        // The key deliberately does not follow. It is what code and the
        // grant-all command name a group by, and renaming the label is a
        // presentation change — the same split §5.4 makes between a field's key
        // and its label, for the same reason.
        $group->setLabel($label);
        $this->entityManager->flush();
    }

    public function delete(PermissionGroup $group): void
    {
        // Members are detached by the join table's cascade and the grants by
        // theirs; nobody is deleted, they simply stop being in it.
        $this->entityManager->remove($group);
        $this->entityManager->flush();
    }

    /**
     * Replaces the group's grants with what the matrix says.
     *
     * Replace rather than merge, because the matrix is the whole picture: a cell
     * set back to "none" is somebody removing a permission, and merging would
     * make that the one edit the screen could not perform.
     *
     * Unknown module keys and unknown actions are ignored rather than refused.
     * The form is generated from the customer's own installed modules, so
     * anything else came from a hand-edited request, and the honest response to
     * that is to grant nothing rather than to explain.
     *
     * @param array<string, array<string, string>> $matrix module key => action => scope, or '' for none
     */
    public function applyGrants(PermissionGroup $group, array $matrix): void
    {
        $existing = [];
        foreach ($group->getGrants() as $grant) {
            $existing[$grant->getModuleKey()][$grant->getAction()->value] = $grant;
        }

        foreach ($matrix as $moduleKey => $actions) {
            foreach ($actions as $actionKey => $scopeKey) {
                $action = ModuleAction::tryFrom($actionKey);

                if ($action === null) {
                    continue;
                }

                $scope = PermissionScope::tryFrom($scopeKey);
                $grant = $existing[$moduleKey][$actionKey] ?? null;

                if ($scope === null) {
                    // Not granted. Removing the row rather than storing a third
                    // state keeps "no grant" one thing instead of two.
                    if ($grant !== null) {
                        $group->removeGrant($grant);
                        $this->entityManager->remove($grant);
                    }

                    continue;
                }

                if ($grant === null) {
                    $this->entityManager->persist(
                        PermissionGrant::forGroup($group, $moduleKey, $action, $scope),
                    );

                    continue;
                }

                $grant->setScope($scope);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Replaces the group's membership.
     *
     * @param list<int> $userIds
     */
    public function setMembers(PermissionGroup $group, array $userIds): void
    {
        $wanted = [];
        foreach ($this->users->findBy(['id' => $userIds]) as $user) {
            $wanted[(int) $user->getId()] = $user;
        }

        foreach ($this->users->findAll() as $user) {
            $id = (int) $user->getId();

            if (isset($wanted[$id])) {
                $user->addPermissionGroup($group);
            } else {
                $user->removePermissionGroup($group);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * What one group grants, as the matrix renders it.
     *
     * @return array<string, array<string, string>> module key => action => scope value, or ''
     */
    public static function matrixOf(PermissionGroup $group): array
    {
        $matrix = [];

        foreach ($group->getGrants() as $grant) {
            $matrix[$grant->getModuleKey()][$grant->getAction()->value] = $grant->getScope()->value;
        }

        return $matrix;
    }

    private function refuseBadName(string $label, ?PermissionGroup $except): void
    {
        if ($label === '') {
            throw GroupChangeRefused::noName();
        }

        foreach ($this->groups->all() as $group) {
            if ($group !== $except && mb_strtolower($group->getLabel()) === mb_strtolower($label)) {
                throw GroupChangeRefused::nameTaken($label);
            }
        }
    }

    /**
     * A key nothing else is using, derived from the name.
     *
     * Names are refused when they collide, so this normally succeeds first time;
     * the counter is for the cases that slug the same way without being the same
     * word — "Sales team" and "Sales, team".
     */
    private function freeKey(string $label): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)), '_');
        $base = $base === '' ? 'group' : mb_substr($base, 0, 50);

        $key = $base;
        $suffix = 2;

        while ($this->groups->findOneByKey($key) !== null) {
            $key = sprintf('%s_%d', $base, $suffix);
            ++$suffix;
        }

        return $key;
    }
}

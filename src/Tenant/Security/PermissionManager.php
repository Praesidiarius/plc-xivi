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
use App\Tenant\Entity\User;
use App\Tenant\Repository\PermissionGroupRepository;
use App\Tenant\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * The write side of the permission model (§7.5): groups, what they hold, who is
 * in them, and the grants made to one person directly.
 *
 * Kept out of the controllers for the same reason UserManager is: the rules
 * about what a change may do belong next to the change, not next to the HTTP.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PermissionManager
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

        // Not granted removes the row rather than storing a third state, which
        // keeps "no grant" one thing instead of two.
        $this->applyMatrix(
            $matrix,
            $existing,
            static fn (string $moduleKey, ModuleAction $action, PermissionScope $scope): PermissionGrant => PermissionGrant::forGroup($group, $moduleKey, $action, $scope),
            static function (PermissionGrant $grant) use ($group): void { $group->removeGrant($grant); },
        );
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
     * Grants made to one person directly, on top of their groups.
     *
     * The exception rather than the rule — a grant made to a job survives the
     * person leaving it and a grant made to a person does not — but the rule
     * cannot cover "Anna, and only Anna, may also export" without inventing a
     * group of one, which is a group nobody can read the purpose of.
     *
     * `$inherited` is what the groups gave **when the form was drawn**, not what
     * they give now. The two differ exactly when somebody unticks a group in the
     * same save, and using the current state there would quietly convert the
     * group's grant into a personal one — the person would keep a permission
     * they were just removed from the group for.
     *
     * @param array<string, array<string, string>> $matrix
     * @param array<string, array<string, string>> $inherited
     */
    public function applyUserGrants(User $user, array $matrix, array $inherited): void
    {
        $existing = [];
        foreach ($user->getPermissionGrants() as $grant) {
            $existing[$grant->getModuleKey()][$grant->getAction()->value] = $grant;
        }

        // The form asks what this person may do, not what to store: a cell
        // showing the group's own answer back is somebody leaving it alone, and
        // writing that down would fill the table with grants that change
        // nothing and then have to be reasoned about forever. Only the part
        // wider than the groups is a personal grant.
        $matrix = self::withoutWhatGroupsAlreadyGive($matrix, $inherited);

        $this->applyMatrix(
            $matrix,
            $existing,
            fn (string $moduleKey, ModuleAction $action, PermissionScope $scope): PermissionGrant => PermissionGrant::forUser($user, $moduleKey, $action, $scope),
            static function (PermissionGrant $grant) use ($user): void { $user->removePermissionGrant($grant); },
        );
    }

    /**
     * Replaces which groups one person is in.
     *
     * @param list<int> $groupIds
     */
    public function setGroupsOf(User $user, array $groupIds): void
    {
        $wanted = array_flip($groupIds);

        foreach ($this->groups->all() as $group) {
            if (isset($wanted[(int) $group->getId()])) {
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
        return self::matrixOfGrants($group->getGrants());
    }

    /**
     * What one person has been granted *directly*, which is the only part their
     * own matrix may edit.
     *
     * @return array<string, array<string, string>>
     */
    public static function ownMatrixOf(User $user): array
    {
        return self::matrixOfGrants($user->getPermissionGrants());
    }

    /**
     * What their groups already give them, folded the way the resolver folds it.
     *
     * Shown beside the editable cells rather than merged into them. Merging would
     * mean somebody granting a thing twice and then being unable to work out why
     * removing it changed nothing — the grant they removed was never the one
     * doing the work.
     *
     * @return array<string, array<string, string>>
     */
    public static function inheritedMatrixOf(User $user): array
    {
        $matrix = [];

        foreach ($user->getPermissionGroups() as $group) {
            foreach ($group->getGrants() as $grant) {
                $module = $grant->getModuleKey();
                $action = $grant->getAction()->value;
                $scope = $grant->getScope();

                $existing = isset($matrix[$module][$action])
                    ? PermissionScope::from($matrix[$module][$action])
                    : null;

                $matrix[$module][$action] = ($existing?->widest($scope) ?? $scope)->value;
            }
        }

        return $matrix;
    }

    /**
     * The submitted matrix reduced to what the groups do not already cover.
     *
     * A cell equal to or narrower than the group's grant becomes "none", which
     * removes any personal grant sitting there — so a redundant one left over
     * from before the group existed is tidied away by the next save rather than
     * lingering as a row nobody can explain.
     *
     * @param array<string, array<string, string>> $matrix
     * @param array<string, array<string, string>> $inherited
     *
     * @return array<string, array<string, string>>
     */
    private static function withoutWhatGroupsAlreadyGive(array $matrix, array $inherited): array
    {
        foreach ($matrix as $moduleKey => $actions) {
            foreach ($actions as $actionKey => $scopeKey) {
                $scope = PermissionScope::tryFrom($scopeKey);
                $floor = PermissionScope::tryFrom($inherited[$moduleKey][$actionKey] ?? '');

                if ($scope === null || $floor === null) {
                    continue;
                }

                // Adds nothing: the widest of the two is still the group's.
                if ($floor->widest($scope) === $floor) {
                    $matrix[$moduleKey][$actionKey] = '';
                }
            }
        }

        return $matrix;
    }

    /**
     * @param iterable<PermissionGrant> $grants
     *
     * @return array<string, array<string, string>>
     */
    private static function matrixOfGrants(iterable $grants): array
    {
        $matrix = [];

        foreach ($grants as $grant) {
            $matrix[$grant->getModuleKey()][$grant->getAction()->value] = $grant->getScope()->value;
        }

        return $matrix;
    }

    /**
     * The shared half of applying a matrix, whoever holds the grants.
     *
     * @param array<string, array<string, string>>                             $matrix
     * @param array<string, array<string, PermissionGrant>>                    $existing
     * @param \Closure(string, ModuleAction, PermissionScope): PermissionGrant $make
     * @param \Closure(PermissionGrant): void                                  $detach
     */
    private function applyMatrix(array $matrix, array $existing, \Closure $make, \Closure $detach): void
    {
        foreach ($matrix as $moduleKey => $actions) {
            foreach ($actions as $actionKey => $scopeKey) {
                $action = ModuleAction::tryFrom($actionKey);

                if ($action === null) {
                    continue;
                }

                $scope = PermissionScope::tryFrom($scopeKey);
                $grant = $existing[$moduleKey][$actionKey] ?? null;

                if ($scope === null) {
                    if ($grant !== null) {
                        $detach($grant);
                        $this->entityManager->remove($grant);
                    }

                    continue;
                }

                if ($grant === null) {
                    $this->entityManager->persist($make($moduleKey, $action, $scope));

                    continue;
                }

                $grant->setScope($scope);
            }
        }

        $this->entityManager->flush();
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

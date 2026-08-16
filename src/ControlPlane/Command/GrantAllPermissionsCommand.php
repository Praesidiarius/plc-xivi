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

namespace App\ControlPlane\Command;

use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\PermissionGroup;
use App\Tenant\Entity\User;
use App\Tenant\Repository\PermissionGroupRepository;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * Gives one customer's people everything, once, deliberately (§7.5).
 *
 * The upgrade path for an installation that predates permissions. Before this
 * existed, anybody who could sign in could do anything; the migration that
 * added the tables deliberately wrote no grants, because a migration lands for
 * every tenant at once (§4) and deciding what a customer's people may do is not
 * something to do to them in passing. So the decision is a command somebody runs.
 *
 * **It is also the way back in.** Default deny means an installation whose
 * administrators all left is one where nobody can add a contact — and there is no
 * support desk behind this. That is why it ships in the same slice as the
 * migration rather than after the UI.
 *
 * Idempotent, so running it again after installing a module tops that module up
 * rather than complaining. It only ever adds: a scope somebody has narrowed by
 * hand is widened back to All, and no grant is ever removed.
 *
 * Administrators are skipped. ROLE_ADMIN is a bypass rather than a group
 * (§8.4.1), so putting them in one would suggest it could be taken away.
 *
 * **"Every action on every installed module" is meant literally**, which is why
 * this grants neither the areas (XIV-12) nor the store's own axis (XIV-6). Both
 * are deliberate rather than an omission waiting to be fixed: the store's install
 * verb decides what the installation *consists of*, permanently and with no
 * uninstall, and a command whose job is to undo a lock-out has no business
 * handing that to everybody who works there on the way past. An administrator
 * always has it, through the bypass; anybody else is given it on the permission
 * screens, deliberately, by somebody who meant to.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:permissions:grant-all',
    description: "Grant every action on every installed module to one tenant's users",
)]
final readonly class GrantAllPermissionsCommand
{
    /** The well-known group this command creates, so re-running finds it again. */
    public const string GROUP_KEY = 'all_access';

    public function __construct(
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        private MetadataRepository $metadata,
        private PermissionGroupRepository $groups,
        private UserRepository $users,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Tenant slug')]
        string $tenant,
        #[Option(description: 'Skip the confirmation prompt')]
        bool $force = false,
    ): int {
        $found = $this->tenants->findOneBySlug($tenant);

        if ($found === null) {
            $io->error(sprintf('No tenant with slug "%s".', $tenant));

            return Command::FAILURE;
        }

        return $this->switcher->runFor($found, function () use ($io, $tenant, $force): int {
            $modules = $this->metadata->all();

            if ($modules === []) {
                $io->warning(sprintf('Tenant "%s" has no modules installed; there is nothing to grant.', $tenant));

                return Command::SUCCESS;
            }

            $members = array_values(array_filter(
                $this->users->findAll(),
                static fn (User $user): bool => !UserManager::isAdmin($user),
            ));

            $moduleKeys = array_map(static fn ($module): string => $module->getKey(), $modules);

            $io->section(sprintf('Tenant "%s"', $tenant));
            $io->listing([
                sprintf('Modules: %s', implode(', ', $moduleKeys)),
                sprintf('Actions: %s', implode(', ', ModuleAction::values())),
                sprintf('Users to add: %d (administrators excluded)', \count($members)),
            ]);

            // Said plainly rather than in a warning, because this is a widening
            // and the thing worth being sure about is the tenant, not the risk.
            $io->text(sprintf(
                'Every listed user will be able to do every listed action on every listed module, on all records.',
            ));

            if (!$force && !$io->confirm('Grant it?', false)) {
                $io->text('Nothing was granted.');

                return Command::SUCCESS;
            }

            $group = $this->groups->findOneByKey(self::GROUP_KEY);

            if ($group === null) {
                $group = new PermissionGroup(self::GROUP_KEY, 'All access');
                $this->entityManager->persist($group);
            }

            $added = $this->grantEverything($group, $moduleKeys);
            $joined = 0;

            foreach ($members as $user) {
                if (!$user->getPermissionGroups()->contains($group)) {
                    $user->addPermissionGroup($group);
                    ++$joined;
                }
            }

            $this->entityManager->flush();

            $io->success(sprintf(
                '%d grant(s) added and %d user(s) joined "%s".',
                $added,
                $joined,
                $group->getLabel(),
            ));

            if ($added === 0 && $joined === 0) {
                $io->text('Everything was already in place.');
            }

            return Command::SUCCESS;
        });
    }

    /**
     * Every action on every module, at the widest scope, added to the group.
     *
     * Existing grants are widened rather than duplicated — the unique index would
     * refuse a second row for the same holder, module and action, and a scope
     * somebody narrowed by hand is exactly what this command is being run to
     * undo.
     *
     * @param list<string> $moduleKeys
     *
     * @return int how many grants were created or widened
     */
    private function grantEverything(PermissionGroup $group, array $moduleKeys): int
    {
        $existing = [];
        foreach ($group->getGrants() as $grant) {
            $existing[$grant->getModuleKey()][(string) $grant->getAction()->value] = $grant;
        }

        $changed = 0;

        foreach ($moduleKeys as $moduleKey) {
            foreach (ModuleAction::cases() as $action) {
                $grant = $existing[$moduleKey][$action->value] ?? null;

                if ($grant === null) {
                    $this->entityManager->persist(
                        PermissionGrant::forGroup($group, $moduleKey, $action, PermissionScope::All),
                    );
                    ++$changed;

                    continue;
                }

                if ($grant->getScope() !== PermissionScope::All) {
                    $grant->setScope(PermissionScope::All);
                    ++$changed;
                }
            }
        }

        return $changed;
    }
}

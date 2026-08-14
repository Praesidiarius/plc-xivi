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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;

/**
 * Creates a user inside one tenant's database.
 *
 * Takes the tenant explicitly and switches to it, rather than assuming the
 * ambient context: the callers are console commands and provisioning, where
 * there is no request to have resolved one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class UserCreator
{
    public function __construct(
        private TenantSwitcher $switcher,
        private UserManager $manager,
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
        if (trim($email) === '') {
            throw new \InvalidArgumentException('A user needs an email address: it is the login.');
        }

        // The construction itself belongs to UserManager, which is what the
        // screens use: one place that knows how a user is built, hashed and
        // stored, rather than two that drift the first time one of them grows a
        // rule the other has not heard of.
        return $this->switcher->runFor($tenant, function () use ($email, $name, $password, $roles, $tenant): string {
            try {
                [, $plaintext] = $this->manager->create($email, $name, $roles, $password);
            } catch (UserChangeRefused $e) {
                // The console and provisioning have said "already exists" in
                // these words since before there were screens; keep saying it.
                throw UserAlreadyExists::in($tenant, mb_strtolower(trim($email)), $e);
            }

            return $plaintext;
        });
    }
}

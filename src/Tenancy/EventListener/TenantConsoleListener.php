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

namespace App\Tenancy\EventListener;

use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lets any console command run against one tenant's database:
 *
 *     TENANT=acme bin/console dbal:run-sql --connection=tenant 'SELECT 1'
 *     TENANT=acme bin/console doctrine:schema:validate --em=tenant
 *
 * A command has no Host header, so without this there is no way to point the
 * tenant connection anywhere and generic Doctrine commands are unusable against
 * customer databases. Our own commands (tenant:migrate and friends) iterate the
 * registry themselves and need nothing from here.
 *
 * An unknown slug is fatal rather than ignored: quietly running against no tenant
 * is how you end up believing a command did something it did not.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final readonly class TenantConsoleListener
{
    public const string ENV_VAR = 'TENANT';

    public function __construct(
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $slug = $_SERVER[self::ENV_VAR] ?? $_ENV[self::ENV_VAR] ?? null;

        if (!\is_string($slug) || $slug === '') {
            return;
        }

        $tenant = $this->tenants->findOneBySlug($slug);

        if ($tenant === null) {
            throw new \InvalidArgumentException(sprintf(
                'No tenant with slug "%s" (from the %s environment variable).',
                $slug,
                self::ENV_VAR,
            ));
        }

        $this->switcher->switchTo($tenant);

        $event->getOutput()->writeln(
            sprintf('<comment>Running against tenant "%s".</comment>', $tenant->getSlug()),
            $event->getOutput()::VERBOSITY_VERBOSE,
        );
    }
}

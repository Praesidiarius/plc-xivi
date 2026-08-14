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

namespace App\Tenant\EventListener;

use App\Tenant\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Stamps a user's last sign-in.
 *
 * The column existed from the first migration and nothing wrote to it, which is
 * worse than not having it: a support question like "when did they last log in"
 * would have been answered with a confident null.
 *
 * Flushed through the tenant manager, which is the only one that knows this
 * user — by the time the firewall has authenticated, the tenant is resolved.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final readonly class RecordLastLoginListener
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $user->recordLogin();
        $this->entityManager->flush();
    }
}

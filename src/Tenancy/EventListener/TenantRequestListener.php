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

use App\Tenancy\Exception\TenantUnavailableException;
use App\Tenancy\Exception\UnknownTenantHostException;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSwitcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the tenant from the Host header (docs/architecture/deployment.md §4).
 *
 * Runs before routing so that no controller, and no listener that touches tenant
 * data, can run without a tenant — the request either has one or is rejected here.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
final readonly class TenantRequestListener
{
    /**
     * @param list<string> $systemHosts hosts served without a tenant (dev tooling,
     *                                  health checks, container-internal names)
     */
    public function __construct(
        private TenantResolver $resolver,
        private TenantSwitcher $switcher,
        #[Autowire('%app.system_hosts%')]
        private array $systemHosts,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $host = TenantResolver::normalize($event->getRequest()->getHost());

        if (\in_array($host, $this->systemHosts, true)) {
            // No tenant: the tenant connection stays unusable and says so if touched.
            $this->switcher->clear();

            return;
        }

        try {
            $tenant = $this->resolver->resolve($host);
        } catch (UnknownTenantHostException $e) {
            $this->switcher->clear();

            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        if (!$tenant->getStatus()->servesRequests()) {
            $this->switcher->clear();

            throw new HttpException(
                Response::HTTP_SERVICE_UNAVAILABLE,
                (new TenantUnavailableException($tenant))->getMessage(),
            );
        }

        $this->switcher->switchTo($tenant);
    }
}

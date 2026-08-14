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

namespace App\Controller;

use App\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder landing page, so that signing in has somewhere to arrive. It gets
 * replaced by something real once there are modules to show.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function __invoke(TenantContext $context): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $context->getTenant(),
        ]);
    }
}

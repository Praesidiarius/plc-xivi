<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder landing page, so that signing in has somewhere to arrive. It gets
 * replaced by something real once there are modules to show.
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

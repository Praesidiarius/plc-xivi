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

use App\Dashboard\Dashboard;
use App\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The landing page, which stopped being a placeholder when it got something to
 * say (XIV-81).
 *
 * It used to hold a tile grid and two empty states, with a docblock promising it
 * would be replaced "once there are modules to show". What replaced it is not a
 * bigger controller: everything the page consists of is a
 * {@see \App\Dashboard\DashboardWidget}, and this asks {@see Dashboard} for the
 * ones this reader should see. Adding the *next* thing to the landing page is a
 * class, not an edit here — which is the property the whole arrangement is for.
 *
 * **No `#[IsGranted]`, and nothing to grant.** The dashboard is where signing in
 * lands, so it is behind the firewall and nothing else; every widget on it
 * decides for itself what this reader may be told, and the follow-up widget in
 * particular resolves permissions per module rather than trusting the page it is
 * drawn on. A route-level grant would be a fourth answer to a question three
 * seams already answer (§8.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function __invoke(TenantContext $context, Dashboard $dashboard): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $context->getTenant(),
            'panels' => $dashboard->panels(),
        ]);
    }
}

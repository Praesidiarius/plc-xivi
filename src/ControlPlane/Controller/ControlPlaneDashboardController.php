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

namespace App\ControlPlane\Controller;

use App\ControlPlane\Security\ControlPlaneHost;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where signing in to the control plane lands, and there is nothing on it
 * (XIV-57).
 *
 * **A placeholder, deliberately and temporarily.** [XIV-58] is the tenant list —
 * the first thing an operator actually comes here to look at — and building a
 * fragment of it now to avoid an empty page would mean writing the list twice
 * and reviewing the security boundary underneath it at the same time as the
 * screen on top. This ticket is the boundary. When XIV-58 lands, **this
 * controller is replaced by the tenant list**, and this docblock is how the next
 * person knows that was always the plan rather than something forgotten.
 *
 * `DashboardController` had exactly this shape once, promising to be replaced
 * "once there are modules to show", and it was: XIV-81 turned it into a page
 * made of widgets. The same arc is expected here, and the same rule applies —
 * the replacement is not a bigger controller.
 *
 * **No `#[IsGranted]`, and nothing to grant.** `access_control` requires
 * `ROLE_OPERATOR` for everything under {@see ControlPlaneHost::PATH_PREFIX}, and
 * every operator has that role and only that role. There is no operator
 * permission model and inventing one before there is a second kind of operator
 * would be modelling a guess (see `Operator`).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ControlPlaneDashboardController extends AbstractController
{
    #[Route(ControlPlaneHost::PATH_PREFIX . '/', name: 'control_plane_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('control_plane/index.html.twig');
    }
}

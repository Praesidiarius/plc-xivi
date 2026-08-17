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
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Signing in to the control plane, and out of it (XIV-57).
 *
 * The counterpart of `App\Controller\SecurityController`, and separate from it
 * on purpose rather than through a shared base class with two configurations.
 * The two pages look alike and are not the same thing: that one is a customer's
 * front door, drawn with the customer's own mark and headed by their hostname,
 * and this one is the platform's. Folding them together would put the tenant
 * firewall's route and the control plane's route in one file, which is precisely
 * the pairing this ticket exists to keep apart — and the first change either one
 * needs would arrive as a conditional on which firewall is asking.
 *
 * **The paths are built from {@see ControlPlaneHost::PATH_PREFIX}** rather than
 * written out, because that constant is what the request listener gates on and a
 * route sitting outside it would be a control-plane page reachable on every
 * customer's hostname.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ControlPlaneSecurityController extends AbstractController
{
    #[Route(ControlPlaneHost::PATH_PREFIX . '/login', name: 'control_plane_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('control_plane_home');
        }

        return $this->render('control_plane/login.html.twig', [
            // Symfony's own sentence, unelaborated, for the same reason the
            // tenant page keeps it: saying which half was wrong turns the form
            // into a way of finding out which addresses are operators.
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_email' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route(ControlPlaneHost::PATH_PREFIX . '/logout', name: 'control_plane_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key of the control_plane firewall in security.yaml.');
    }
}

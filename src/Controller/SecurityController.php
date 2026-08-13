<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('security/login.html.twig', [
            // Deliberately whatever Symfony gives us: "Invalid credentials"
            // rather than saying which half was wrong, so the form cannot be
            // used to find out which addresses have accounts at this customer.
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_email' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key in security.yaml.');
    }
}

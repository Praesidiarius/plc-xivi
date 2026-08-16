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

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkAuthenticationException;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('security/login.html.twig', [
            // Deliberately whatever Symfony gives us: "Invalid credentials"
            // rather than saying which half was wrong, so the form cannot be
            // used to find out which addresses have accounts at this customer.
            'error' => $error,
            'last_email' => $authenticationUtils->getLastUsername(),
            // An invitation that did not work is a different situation from a
            // mistyped password, and the person in front of it is different too:
            // somebody with no account here yet, holding a link that says nothing
            // about why it was refused (XIV-1). Symfony's own message names the
            // cause; this says what to do about it. The distinction is made on
            // the exception rather than in the template, because "which kind of
            // failure was this" is not a question Twig should be answering.
            //
            // Deliberately only this one. A deactivated account arriving on a
            // link is refused by `ActiveUserChecker` instead, and its message —
            // "This account has been deactivated." — is already both the reason
            // and the instruction; suggesting a fresh invitation on top of it
            // would send them back to somebody who cannot help until the account
            // is reactivated.
            'invitation_failed' => $error instanceof InvalidLoginLinkAuthenticationException,
        ]);
    }

    /**
     * Where an invitation link lands (XIV-1).
     *
     * Never executed. `login_link` in security.yaml names this route as its
     * `check_route`, so the authenticator intercepts the request during the
     * firewall's listener and answers with a redirect — to the account page on a
     * valid signature, to the sign-in page on anything else. The route exists so
     * that there is something to generate a URL for, which is Symfony's own
     * prescribed shape for this and the same one `logout` above has.
     */
    #[Route('/invitation', name: 'invitation_accept', methods: ['GET'])]
    public function invitation(): never
    {
        throw new \LogicException('Intercepted by the login_link key in security.yaml.');
    }

    #[Route('/logout', name: 'logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key in security.yaml.');
    }
}

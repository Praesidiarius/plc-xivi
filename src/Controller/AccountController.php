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

use App\Tenant\Entity\User;
use App\Tenant\Security\UserChangeRefused;
use App\Tenant\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Your own account: your name, and your password.
 *
 * Everybody gets this, administrator or not. Without it a person handed a
 * generated password (§8.5) could never change it, and would keep the one their
 * administrator read off a screen for as long as they worked there.
 *
 * Changing the password needs the current one. Not because it is secret from its
 * owner, but because an unattended session should not be enough to take an
 * account over.
 *
 * Your email is deliberately not editable here. It is the login and the security
 * identifier, and letting somebody change it out from under their own session is
 * a sign-out disguised as a settings page — an administrator does it on /users.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/account')]
final class AccountController extends AbstractController
{
    public function __construct(private readonly UserManager $users)
    {
    }

    #[Route('', name: 'account', methods: ['GET', 'POST'])]
    public function account(Request $request): Response
    {
        $user = $this->getUser();

        // The firewall requires ROLE_USER everywhere but /login, so this is a
        // type narrowing rather than an access check.
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Read before the change, because changing it is what clears the hold —
        // and somebody who has just been let in should be sent on rather than
        // left looking at the page that was holding them.
        $held = $user->mustChangePassword();

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
            $this->handle($user, $request);

            if ($held && !$user->mustChangePassword()) {
                return $this->redirectToRoute('dashboard');
            }
        }

        return $this->render('account/index.html.twig', [
            'account' => $user,
            'minimum' => User::MINIMUM_PASSWORD_LENGTH,
            'held' => $user->mustChangePassword(),
        ]);
    }

    private function handle(User $user, Request $request): void
    {
        try {
            if ($request->request->get('action') === 'password') {
                $new = (string) $request->request->get('new_password');
                $repeat = (string) $request->request->get('repeat_password');

                if ($new !== $repeat) {
                    // Checked here rather than in the manager: it is a fact about
                    // this form having two boxes, not about what a password is.
                    $this->addFlash('warning', 'Those two passwords are not the same.');

                    return;
                }

                if (mb_strlen($new) < User::MINIMUM_PASSWORD_LENGTH) {
                    $this->addFlash('warning', sprintf(
                        'Use at least %d characters.',
                        User::MINIMUM_PASSWORD_LENGTH,
                    ));

                    return;
                }

                $this->users->changeOwnPassword($user, (string) $request->request->get('current_password'), $new);
                $this->addFlash('success', 'Your password has been changed.');

                return;
            }

            $this->users->updateProfile($user, $user->getEmail(), (string) $request->request->get('name'));
            $this->addFlash('success', 'Saved.');
        } catch (UserChangeRefused $e) {
            $this->addFlash('warning', $e->getMessage());
        }
    }
}

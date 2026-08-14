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
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserChangeRefused;
use App\Tenant\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The people who can sign in to this customer's installation (§8).
 *
 * Until this existed the only way to add a colleague was a console command
 * against the customer's database, which is not something a customer has. It is
 * also where §8.4's real authorization model will attach: today a user is an
 * administrator or is not, and this is the screen that will grow the rest.
 *
 * Administrator-only, and it refuses every change that would leave nobody able to
 * administer the installation — there is no support desk behind this to phone.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/users')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserManager $users,
        private readonly UserRepository $repository,
    ) {
    }

    #[Route('', name: 'user_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $this->users->all(),
        ]);
    }

    #[Route('/new', name: 'user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('manage-users', (string) $request->request->get('_token'))) {
            try {
                [$user, $password] = $this->users->create(
                    email: (string) $request->request->get('email'),
                    name: (string) $request->request->get('name'),
                    roles: $request->request->getBoolean('admin') ? [UserManager::ROLE_ADMIN] : [],
                );

                // The one moment this password exists in the clear. There is no
                // mailer yet (§8.5), so it is read off the screen — and said
                // plainly that it will not be shown again, because it will not.
                $this->addFlash('password', sprintf(
                    'Created %s. Their password is %s — copy it now, it cannot be shown again.',
                    $user->getEmail(),
                    $password,
                ));

                return $this->redirectToRoute('user_index');
            } catch (UserChangeRefused $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->render('user/form.html.twig', [
            'user' => null,
            'submitted' => $request->request->all(),
        ]);
    }

    #[Route('/{id}', name: 'user_edit', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->user($id);

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('manage-users', (string) $request->request->get('_token'))) {
            try {
                $this->users->updateProfile(
                    $user,
                    email: (string) $request->request->get('email'),
                    name: (string) $request->request->get('name'),
                );

                $this->users->setRoles(
                    $user,
                    $request->request->getBoolean('admin') ? [UserManager::ROLE_ADMIN] : [],
                    $this->currentUser(),
                );

                $this->addFlash('success', sprintf('Saved %s.', $user->getEmail()));

                return $this->redirectToRoute('user_index');
            } catch (UserChangeRefused $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->render('user/form.html.twig', [
            'user' => $user,
            'submitted' => [],
        ]);
    }

    #[Route('/{id}/active', name: 'user_active', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function active(int $id, Request $request): Response
    {
        $user = $this->user($id);

        if ($this->isCsrfTokenValid('manage-users', (string) $request->request->get('_token'))) {
            $active = $request->request->getBoolean('active');

            try {
                $this->users->setActive($user, $active, $this->currentUser());
                $this->addFlash('success', sprintf(
                    '%s can %s sign in.',
                    $user->getEmail(),
                    $active ? 'now' : 'no longer',
                ));
            } catch (UserChangeRefused $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->redirectToRoute('user_index');
    }

    /**
     * A new generated password for somebody who has lost theirs.
     *
     * Administrator-only precisely because it does not need the old one. The
     * account owner changing their own goes through /account, which does.
     */
    #[Route('/{id}/password', name: 'user_password', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function password(int $id, Request $request): Response
    {
        $user = $this->user($id);

        if ($this->isCsrfTokenValid('manage-users', (string) $request->request->get('_token'))) {
            $this->addFlash('password', sprintf(
                'New password for %s: %s — copy it now, it cannot be shown again.',
                $user->getEmail(),
                $this->users->resetPassword($user),
            ));
        }

        return $this->redirectToRoute('user_index');
    }

    private function user(int $id): User
    {
        return $this->repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}

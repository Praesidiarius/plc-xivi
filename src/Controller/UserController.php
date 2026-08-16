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
use App\Tenant\Mail\MailSendFailed;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\PermissionArea;
use App\Tenant\Security\PermissionManager;
use App\Tenant\Security\UserChangeRefused;
use App\Tenant\Security\UserInvitations;
use App\Tenant\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

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
        private readonly UserInvitations $invitations,
        private readonly UserRepository $repository,
        private readonly PermissionManager $permissions,
        private readonly MetadataRepository $metadata,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'user_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $this->users->all(),
        ]);
    }

    /**
     * Two ways to add a colleague, and the administrator picks (XIV-1).
     *
     * They are genuinely two, not one with a switch on the end: the invitation
     * path never generates a password at all, because a generated one that was
     * never needed is a credential sitting on the account with nobody to rotate
     * it. §8.8 for the rest.
     */
    #[Route('/new', name: 'user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('manage-users', (string) $request->request->get('_token'))) {
            $email = (string) $request->request->get('email');
            $name = (string) $request->request->get('name');
            $roles = $request->request->getBoolean('admin') ? [UserManager::ROLE_ADMIN] : [];

            try {
                if ($request->request->get('method') === 'invite') {
                    return $this->invited($email, $name, $roles);
                }

                [$user, $password] = $this->users->create(email: $email, name: $name, roles: $roles);

                // The one moment this password exists in the clear. It is read
                // off the screen and said plainly that it will not be shown
                // again, because it will not (§8.5).
                $this->addFlash('password', $this->translator->trans('flash.user_created', [
                    '%email%' => $user->getEmail(),
                    '%password%' => $password,
                ]));

                return $this->redirectToRoute('user_index');
            } catch (UserChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        // No permissions section when adding: grants need somebody to belong to,
        // and asking for them before the account exists would be two screens
        // pretending to be one.
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

                // Read before the groups change: the matrix that was submitted
                // was drawn against *this* floor, and unticking a group in the
                // same save would otherwise turn its grant into a personal one.
                $inherited = PermissionManager::inheritedMatrixOf($user);

                $this->permissions->setGroupsOf($user, array_values(array_map(
                    static fn (mixed $id): int => (int) $id,
                    $request->request->all('groups'),
                )));

                /** @var array<string, array<string, string>> $matrix */
                $matrix = $request->request->all('grants');
                $this->permissions->applyUserGrants($user, $matrix, $inherited);

                $this->addFlash('success', $this->translator->trans('flash.user_saved', ['%email%' => $user->getEmail()]));

                return $this->redirectToRoute('user_index');
            } catch (UserChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->render('user/form.html.twig', [
            'user' => $user,
            'submitted' => [],
            'modules' => $this->metadata->all(),
            // Grantable, and no module's — see PermissionArea (XIV-12).
            'areas' => PermissionArea::all(),
            'actions' => ModuleAction::cases(),
            'scopes' => PermissionScope::cases(),
            'groups' => $this->permissions->all(),
            'inGroups' => array_map(
                static fn ($group): int => (int) $group->getId(),
                $user->getPermissionGroups()->toArray(),
            ),
            // The person's own grants are the only cells this screen may edit;
            // what their groups give them is shown beside those, never merged
            // into them (see PermissionManager::inheritedMatrixOf).
            'matrix' => PermissionManager::ownMatrixOf($user),
            'inherited' => PermissionManager::inheritedMatrixOf($user),
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
                $this->addFlash('success', $this->translator->trans(
                    $active ? 'flash.user_reactivated' : 'flash.user_deactivated',
                    ['%email%' => $user->getEmail()],
                ));
            } catch (UserChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
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
            $this->addFlash('password', $this->translator->trans('flash.password_reset', [
                '%email%' => $user->getEmail(),
                '%password%' => $this->users->resetPassword($user),
            ]));
        }

        return $this->redirectToRoute('user_index');
    }

    /**
     * Another invitation, for the mail that never arrived or the day that passed.
     *
     * A 24-hour link with no way to issue a second one would be broken by design:
     * somebody who reads their mail on Monday cannot be told to have read it on
     * Sunday. Sending this one retires the previous one — see
     * `UserInvitations::send()` for why there is never more than one live at a
     * time — and the clock starts again.
     *
     * Offered only for an account that has no password, which is what keeps
     * "invite" from becoming a way past a credential somebody chose; the refusal
     * is the manager's rather than this screen's, so the console and XIV-64 get
     * it too.
     */
    #[Route('/{id}/invite', name: 'user_invite', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function invite(int $id, Request $request): Response
    {
        $user = $this->user($id);

        if ($this->isCsrfTokenValid('manage-users', (string) $request->request->get('_token'))) {
            try {
                $this->announceInvitation($user, $this->invitations->send($user));
            } catch (UserChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            } catch (MailSendFailed $e) {
                $this->addFlash('warning', $this->translator->trans('flash.invitation_failed', [
                    '%email%' => $user->getEmail(),
                    '%reason%' => $e->getMessage(),
                ]));
            }
        }

        return $this->redirectToRoute('user_index');
    }

    /**
     * Create, then send — and the account survives the send failing.
     *
     * Deliberately not one transaction. A tenant whose SMTP server is refusing
     * connections would otherwise be a tenant who cannot add a colleague at all,
     * and the half that worked is worth keeping: the account is there, it is
     * shown as awaiting an invitation, and the button beside it sends another
     * one. Rolling it back would turn a retryable problem into a form somebody
     * has to fill in again.
     *
     * @param list<string> $roles
     *
     * @throws UserChangeRefused
     */
    private function invited(string $email, string $name, array $roles): Response
    {
        $user = $this->users->createWithoutPassword($email, $name, $roles);

        try {
            $this->announceInvitation($user, $this->invitations->send($user));
        } catch (MailSendFailed $e) {
            $this->addFlash('warning', $this->translator->trans('flash.user_created_not_invited', [
                '%email%' => $user->getEmail(),
                '%reason%' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('user_index');
    }

    private function announceInvitation(User $user, \DateTimeImmutable $expires): void
    {
        $this->addFlash('success', $this->translator->trans('flash.user_invited', [
            '%email%' => $user->getEmail(),
            // The real remaining lifetime rather than a literal 24, computed the
            // same way the mail computes it, so the sentence stays true if the
            // configured one ever moves.
            '%hours%' => UserInvitations::hoursUntil($expires),
        ]));
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

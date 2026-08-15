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

use App\Tenant\Entity\PermissionGroup;
use App\Tenant\Security\GroupChangeRefused;
use App\Tenant\Security\PermissionArea;
use App\Tenant\Security\PermissionManager;
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
 * Deciding who may do what, as a screen (§7.5).
 *
 * The permission model has been enforced since the voters landed; until this
 * existed the only way to grant anything was a console command against the
 * customer's database, which is not a thing a customer has. Same argument
 * §8.4.1 made for building the user manager before the permissions themselves.
 *
 * **Groups rather than people.** A grant made to a job survives the person
 * leaving it, and "everybody in support may do this" is a sentence somebody can
 * check. Granting to one person directly is the exception and comes next.
 *
 * Administrators only, and deliberately not one of the module permissions: this
 * is the screen that decides what those permissions mean, so gating it with one
 * of them would be circular.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/users/groups')]
#[IsGranted('ROLE_ADMIN')]
final class PermissionGroupController extends AbstractController
{
    private const string CSRF = 'manage-groups';

    public function __construct(
        private readonly PermissionManager $groups,
        private readonly MetadataRepository $metadata,
        private readonly UserManager $users,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'group_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('permission_group/index.html.twig', [
            'groups' => $this->groups->all(),
        ]);
    }

    #[Route('/new', name: 'group_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($this->submitted($request)) {
            try {
                $group = $this->groups->create((string) $request->request->get('label'));

                // Straight to the matrix: a group with no grants does nothing,
                // so naming one is the beginning of the job rather than the end.
                $this->addFlash('success', $this->translator->trans('flash.group_created', ['%group%' => $group->getLabel()]));

                return $this->redirectToRoute('group_edit', ['id' => $group->getId()]);
            } catch (GroupChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->render('permission_group/new.html.twig', [
            'submitted' => $request->request->all(),
        ]);
    }

    #[Route('/{id}', name: 'group_edit', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $group = $this->group($id);

        if ($this->submitted($request)) {
            try {
                $this->groups->rename($group, (string) $request->request->get('label'));

                /** @var array<string, array<string, string>> $matrix */
                $matrix = $request->request->all('grants');
                $this->groups->applyGrants($group, $matrix);

                // Keyed by user id in the form, so that a checkbox is addressable
                // and an unticked one is simply absent.
                $this->groups->setMembers($group, array_values(array_map(
                    static fn (mixed $id): int => (int) $id,
                    $request->request->all('members'),
                )));

                $this->addFlash('success', $this->translator->trans('flash.group_saved', ['%group%' => $group->getLabel()]));

                return $this->redirectToRoute('group_index');
            } catch (GroupChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->render('permission_group/form.html.twig', [
            'group' => $group,
            'modules' => $this->metadata->all(),
            // What is grantable but belongs to no module (XIV-12). The catalogue
            // is still worked out at runtime — now from the enum crossed with
            // modules *and* these.
            'areas' => PermissionArea::all(),
            'actions' => ModuleAction::cases(),
            'scopes' => PermissionScope::cases(),
            'matrix' => PermissionManager::matrixOf($group),
            'users' => $this->users->all(),
            'members' => $this->memberIds($group),
        ]);
    }

    #[Route('/{id}/delete', name: 'group_confirm_delete', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function confirmDelete(int $id): Response
    {
        $group = $this->group($id);

        return $this->render('permission_group/confirm_delete.html.twig', [
            'group' => $group,
            // The number is the point: it says how many people are about to lose
            // something, which is the fact that makes the answer obvious.
            'members' => \count($this->memberIds($group)),
            'grants' => \count($group->getGrants()),
        ]);
    }

    #[Route('/{id}/delete', name: 'group_delete', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $group = $this->group($id);

        if ($this->submitted($request)) {
            $label = $group->getLabel();
            $this->groups->delete($group);

            $this->addFlash('success', $this->translator->trans('flash.group_deleted', ['%group%' => $label]));
        }

        return $this->redirectToRoute('group_index');
    }

    /** @return list<int> */
    private function memberIds(PermissionGroup $group): array
    {
        $ids = [];

        foreach ($group->getMembers() as $member) {
            $ids[] = (int) $member->getId();
        }

        return $ids;
    }

    private function submitted(Request $request): bool
    {
        return $request->isMethod('POST')
            && $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'));
    }

    private function group(int $id): PermissionGroup
    {
        return $this->groups->find($id) ?? throw $this->createNotFoundException();
    }
}

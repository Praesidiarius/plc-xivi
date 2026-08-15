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

use App\Tenant\Security\PermissionArea;
use App\Tenant\Settings\TenantProfileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Permission\ModuleAction;

/**
 * The instance's own settings: what this customer is called, and the currency
 * they work in (XIV-12).
 *
 * Not an account page and not a module. `/account` is one person's own settings
 * and everybody has it; this is the installation's, and it is granted — the first
 * thing worth granting that no module owns, which is what PermissionArea exists
 * for.
 *
 * **Two routes on one path, so that reading and changing are separate grants.**
 * Somebody may need to see which currency the instance prices in without being
 * the person who decides it. The `area` argument comes from the route's own
 * defaults purely so `#[IsGranted]` has a subject to name — the check happens
 * before the action runs, which is where it belongs (§8.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/settings/profile')]
final class TenantProfileController extends AbstractController
{
    private const string CSRF = 'tenant-profile';

    public function __construct(
        private readonly TenantProfileManager $profile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'tenant_profile', defaults: ['area' => PermissionArea::Profile->value], methods: ['GET'])]
    #[IsGranted(ModuleAction::View->value, subject: 'area')]
    public function show(Request $request, string $area): Response
    {
        return $this->page($request);
    }

    #[Route('', name: 'tenant_profile_save', defaults: ['area' => PermissionArea::Profile->value], methods: ['POST'])]
    #[IsGranted(ModuleAction::Edit->value, subject: 'area')]
    public function save(Request $request, string $area): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->page($request);
        }

        $this->profile->apply(
            (string) $request->request->get('company_name'),
            (string) $request->request->get('currency'),
        );

        $this->addFlash('success', $this->translator->trans('flash.profile_saved'));

        // Redirect rather than render, so a reload does not repost the form —
        // and so the topbar picks the new company name up on a fresh request.
        return $this->redirectToRoute('tenant_profile');
    }

    private function page(Request $request): Response
    {
        return $this->render('tenant_profile/index.html.twig', [
            'profile' => $this->profile->current(),
            // Named in the language being read, so somebody looks for "Swiss
            // franc" rather than for CHF.
            'currencies' => $this->profile->currencyChoices($request->getLocale()),
            'area' => PermissionArea::Profile->value,
        ]);
    }
}

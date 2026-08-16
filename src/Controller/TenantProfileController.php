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

use App\Tenant\Mail\MailSettingsRefused;
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

        try {
            // Mail first, because it is the half that can refuse (XIV-37). Doing
            // it after the name and the currency would leave those saved and the
            // page reporting a failure, which reads as "nothing was saved" and
            // is not.
            $this->profile->applyMail(
                (string) $request->request->get('mail_sender_address'),
                (string) $request->request->get('mail_smtp_host'),
                $this->port($request),
                (string) $request->request->get('mail_smtp_user'),
                // Empty means "unchanged" here rather than "clear it": the field
                // is rendered blank on every load, because a stored password is
                // never sent back to a browser. Clearing the server clears it.
                $this->submittedPassword($request),
            );
        } catch (MailSettingsRefused $refused) {
            $this->addFlash('error', $this->translator->trans(
                $refused->translatable()->getMessage(),
                $refused->translatable()->getParameters(),
            ));

            return $this->page($request);
        }

        $this->profile->apply(
            (string) $request->request->get('company_name'),
            (string) $request->request->get('currency'),
            (string) $request->request->get('region'),
            $this->paymentTermsDays($request),
        );

        $this->addFlash('success', $this->translator->trans('flash.profile_saved'));

        // Redirect rather than render, so a reload does not repost the form —
        // and so the topbar picks the new company name up on a fresh request.
        return $this->redirectToRoute('tenant_profile');
    }

    /** Blank means "the scheme's default", which is a real answer and not a missing one. */
    private function port(Request $request): ?int
    {
        $port = trim((string) $request->request->get('mail_smtp_port'));

        return $port === '' ? null : (int) $port;
    }

    /**
     * Blank means "this installation does not put due dates on anything", which
     * is a real answer and the one every existing tenant is already giving
     * (XIV-67). Zero is a different one — payable on receipt — so the emptiness
     * is checked before the cast rather than after it, where both would be 0.
     */
    private function paymentTermsDays(Request $request): ?int
    {
        $days = trim((string) $request->request->get('payment_terms_days'));

        return $days === '' ? null : (int) $days;
    }

    /** Blank means "leave the stored one alone"; see TenantProfileManager::applyMail(). */
    private function submittedPassword(Request $request): ?string
    {
        $password = (string) $request->request->get('mail_smtp_password');

        return $password === '' ? null : $password;
    }

    private function page(Request $request): Response
    {
        return $this->render('tenant_profile/index.html.twig', [
            'profile' => $this->profile->current(),
            // Named in the language being read, so somebody looks for "Swiss
            // franc" rather than for CHF.
            'currencies' => $this->profile->currencyChoices($request->getLocale()),
            'regions' => $this->profile->regionChoices($request->getLocale()),
            'area' => PermissionArea::Profile->value,
        ]);
    }
}

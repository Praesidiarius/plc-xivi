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
use App\Tenant\Settings\DisplayTimezone;
use App\Tenant\Settings\LogoFormat;
use App\Tenant\Settings\LogoRefused;
use App\Tenant\Settings\LogoUpload;
use App\Tenant\Settings\TenantProfileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        // The zone list, and what the chosen region would derive on its own
        // (XIV-83).
        private readonly DisplayTimezone $timezones,
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
            // The uploaded mark is proved before anything is written (XIV-49).
            //
            // **The ordering rule below now has two halves to satisfy, and this
            // is how it still holds.** The mail settings could always refuse, and
            // they go first so that a refusal leaves the name and the currency
            // unsaved rather than half-saved under an error message. A logo can
            // refuse too — it may be the wrong format or too large — and putting
            // a second writing step in the sequence would break the rule
            // whichever order the two were in: the first to write has already
            // written by the time the second one refuses. So the logo's refusing
            // is separated from its writing. Constructing a LogoUpload is where a
            // bad file is turned away, it happens before the first flush of the
            // request, and the write itself happens further down with nothing
            // left to object to.
            $logo = $this->uploadedLogo($request);

            // Mail second, because it is the other half that can refuse (XIV-37).
            // Doing it after the name and the currency would leave those saved and
            // the page reporting a failure, which reads as "nothing was saved" and
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
        } catch (MailSettingsRefused|LogoRefused $refused) {
            // Two exception types, one sentence to show. They are separate classes
            // because they are about separate things and a caller may one day want
            // to tell them apart; what this caller does with either is identical,
            // so catching them together is honest rather than lazy.
            $this->addFlash('error', $this->translator->trans(
                $refused->translatable()->getMessage(),
                $refused->translatable()->getParameters(),
            ));

            return $this->page($request);
        }

        // Nothing here can refuse any more, so the order of the last two is a
        // matter of taste rather than of correctness.
        $this->profile->applyLogo($logo, $request->request->getBoolean('logo_remove'));

        $this->profile->apply(
            (string) $request->request->get('company_name'),
            (string) $request->request->get('currency'),
            (string) $request->request->get('region'),
            $this->paymentTermsDays($request),
            (string) $request->request->get('timezone'),
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

    /**
     * The uploaded mark, proved, or null when nothing was sent (XIV-49).
     *
     * **No file is the ordinary submission and not a missing one.** A browser
     * cannot be handed the stored file back, so the input renders empty on every
     * load and every save made for some other reason arrives here with nothing in
     * it. That has to mean "leave the mark alone" — exactly the shape the SMTP
     * password field already has, for the same underlying reason. Taking a logo
     * away is therefore its own control rather than an empty field.
     *
     * The size is looked at twice and only one of those is the rule. This one is
     * an early out on the metadata PHP already has, so a forty-megabyte upload is
     * turned away without being read into memory first; LogoUpload's is the check
     * that decides, on the bytes themselves, and it is the one that would still
     * be there if this method were deleted.
     *
     * @throws LogoRefused
     */
    private function uploadedLogo(Request $request): ?LogoUpload
    {
        $file = $request->files->get('logo');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return null;
        }

        if ($file->getSize() > LogoFormat::MAX_BYTES) {
            throw LogoRefused::tooLarge();
        }

        return LogoUpload::from(
            (string) file_get_contents($file->getPathname()),
            $file->getClientOriginalName(),
        );
    }

    /** Blank means "leave the stored one alone"; see TenantProfileManager::applyMail(). */
    private function submittedPassword(Request $request): ?string
    {
        $password = (string) $request->request->get('mail_smtp_password');

        return $password === '' ? null : $password;
    }

    private function page(Request $request): Response
    {
        $profile = $this->profile->current();

        // What the region alone would derive, if this were left empty (XIV-83).
        // Null for a country with several zones — Spain, the United States — and
        // for a customer who has not chosen a country either, in which case the
        // honest label is that everything falls to UTC. Naming it is the point:
        // the whole reason this setting exists is that a zone nobody chose is a
        // zone nobody can see, so the empty option says which clock it means.
        $derived = $this->timezones->derivedFromRegion($profile->getRegion())
            ?? new \DateTimeZone('UTC');

        return $this->render('tenant_profile/index.html.twig', [
            'profile' => $profile,
            // Named in the language being read, so somebody looks for "Swiss
            // franc" rather than for CHF.
            'currencies' => $this->profile->currencyChoices($request->getLocale()),
            'regions' => $this->profile->regionChoices($request->getLocale()),
            'timezones' => $this->timezones->choices($request->getLocale()),
            'timezoneDerived' => $this->timezones->name($derived, $request->getLocale()),
            // What the upload will take, said in the two places it has to be said
            // (XIV-49): the `accept` attribute, which is a hint the file picker
            // uses and a browser is free to ignore, and the help text, which is
            // what somebody actually reads after their file was refused. Both come
            // off the enum, so the day a format is added or dropped the form
            // cannot go on claiming otherwise.
            'logoAccepts' => LogoFormat::accepted(),
            'logoMaxKib' => LogoFormat::MAX_BYTES / 1024,
            'area' => PermissionArea::Profile->value,
        ]);
    }
}
